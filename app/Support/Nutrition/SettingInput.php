<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionProfile;

/**
 * Перехват следующего сообщения как значения интерактивной настройки или времени
 * приёма.
 *
 * Когда пользователь нажал кнопку в /settings, ключ настройки лежит в
 * awaiting['setting'] профиля. После кнопки «🕐 Поел раньше» тип приёма лежит в
 * awaiting['meal_time']. Ближайшее текстовое/числовое сообщение трактуется как
 * значение и обрабатывается здесь — до обычной логики HandleNumbers/HandleQuestion.
 */
class SettingInput
{
    /**
     * @param  array<string, mixed>  $update
     * @return bool true, если сообщение поглощено как значение настройки/времени приёма
     */
    public static function intercept(array $update, NutritionProfile $profile): bool
    {
        // Ожидание времени приёма — раньше настройки (ключи взаимоисключающи).
        if (self::interceptMealTime($update, $profile)) {
            return true;
        }

        $key = $profile->waiting('setting');
        if (! is_string($key) || $key === '') {
            return false;
        }

        // Ожидание актуально, только пока запрос значения — последнее исходящее.
        // Если после него бот успел спросить что-то ещё (вес, шаги), число юзера
        // относится к тому запросу: сбрасываем устаревший awaiting и отдаём
        // сообщение обычной обработке.
        if (self::lastOutKind($profile) !== 'setting_request') {
            $profile->clearWaiting('setting');

            return false;
        }

        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);
        $text = trim((string) ($update['message']['text'] ?? ''));

        return match ($key) {
            // Подъём: 04:00–12:00.
            'wake_time' => self::applyTime($tg, $profile, $chatId, 'wake_time', $text, 240, 720, 'подъём', '☀️'),
            // Отбой: 20:00–23:59.
            'sleep_time' => self::applyTime($tg, $profile, $chatId, 'sleep_time', $text, 1200, 1439, 'отбой', '🌙'),
            'steps_target' => self::applySteps($tg, $profile, $chatId, $text),
            // Неизвестный ключ — сбрасываем и отдаём сообщение обычной обработке.
            default => self::abandon($profile),
        };
    }

    /**
     * Ожидание времени приёма (после «🕐 Поел раньше»): следующее ЧЧ:ММ — время
     * приёма. Пока ожидание активно И запрос времени — последнее исходящее, любое
     * сообщение трактуется как попытка ввода времени (невалидное → подсказка,
     * ожидание сохраняется). Если бот успел спросить что-то ещё (вес/шаги), ввод
     * относится к тому запросу: сбрасываем устаревший awaiting и пропускаем дальше.
     *
     * @param  array<string, mixed>  $update
     */
    private static function interceptMealTime(array $update, NutritionProfile $profile): bool
    {
        $type = $profile->waiting('meal_time');
        if (! is_string($type) || ! in_array($type, MealPlan::TYPES, true)) {
            return false;
        }

        if (self::lastOutKind($profile) !== 'meal_time_request') {
            $profile->clearWaiting('meal_time');

            return false;
        }

        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);
        $text = trim((string) ($update['message']['text'] ?? ''));

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m)) {
            $tg->send('Не понял время. Пришли в формате ЧЧ:ММ, например 10:00', null, 'meal_time_request', $chatId);

            return true;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            $tg->send('Такого времени не бывает. Пришли в формате ЧЧ:ММ, например 10:00', null, 'meal_time_request', $chatId);

            return true;
        }

        $now = $profile->now();
        Planner::ensureDay($profile, $now);

        $meal = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', $type)
            ->first();

        // Уже отмеченный приём не перезаписываем (иначе затрём eaten_at/фото/фидбек).
        if ($meal !== null && $meal->status === 'eaten') {
            $profile->clearWaiting('meal_time');
            $tg->send(MealPlan::LABELS[$type].' уже отмечен 👌🏻', chatId: $chatId);

            return true;
        }

        if ($meal !== null) {
            Planner::markEaten($profile, $meal, $now->setTime($hours, $minutes), null, null);
        }

        $profile->clearWaiting('meal_time');

        $reply = 'Записал '.MealPlan::LABELS[$type].' в '.sprintf('%02d:%02d', $hours, $minutes).' 👌🏻';
        $tail = MealLogger::windowsTail($profile, $now);
        if ($tail !== '') {
            $reply .= "\n\n".$tail;
        }

        $tg->send($reply, chatId: $chatId);

        return true;
    }

    private static function applyTime(TelegramClient $tg, NutritionProfile $profile, ?int $chatId, string $key, string $text, int $min, int $max, string $label, string $emoji): bool
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m)) {
            $tg->send('Не понял время. Пришли в формате ЧЧ:ММ, например 07:30', null, 'setting_request', $chatId);

            return true;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            $tg->send('Такого времени не бывает. Пришли в формате ЧЧ:ММ, например 07:30', null, 'setting_request', $chatId);

            return true;
        }

        $total = $hours * 60 + $minutes;
        if ($total < $min || $total > $max) {
            $range = sprintf('%02d:%02d–%02d:%02d', intdiv($min, 60), $min % 60, intdiv($max, 60), $max % 60);
            $tg->send('Время вне разумного диапазона ('.$range.'). Пришли ещё раз в формате ЧЧ:ММ.', null, 'setting_request', $chatId);

            return true;
        }

        $value = sprintf('%02d:%02d', $hours, $minutes);
        $profile->setSetting($key, $value);
        $profile->clearWaiting('setting');

        if ($key === 'sleep_time') {
            // Окна сегодняшних приёмов зависят от отбоя; на пустом дне отработает вхолостую.
            Planner::recalculate($profile, $profile->now()->startOfDay());
        }

        $tg->send('Готово, '.$label.' теперь '.$value.' '.$emoji, chatId: $chatId);

        return true;
    }

    private static function applySteps(TelegramClient $tg, NutritionProfile $profile, ?int $chatId, string $text): bool
    {
        if (! preg_match('/^\d+$/', $text)) {
            $tg->send('Пришли число шагов в день (3000–30000)', null, 'setting_request', $chatId);

            return true;
        }

        $steps = (int) $text;
        if ($steps < 3000 || $steps > 30000) {
            $tg->send('Цель вне диапазона. Пришли число от 3000 до 30000.', null, 'setting_request', $chatId);

            return true;
        }

        $profile->setSetting('steps_target', $steps);
        $profile->clearWaiting('setting');
        $tg->send('Новая цель: '.$steps.' шагов 👣', chatId: $chatId);

        return true;
    }

    private static function abandon(NutritionProfile $profile): bool
    {
        $profile->clearWaiting('setting');

        return false;
    }

    /**
     * Последний kind исходящего сообщения именно этого профиля (staleness-guard).
     */
    private static function lastOutKind(NutritionProfile $profile): ?string
    {
        return NutritionMessage::query()
            ->where('profile_id', $profile->id)
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->value('kind');
    }
}
