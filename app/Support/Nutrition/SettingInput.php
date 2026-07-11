<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMessage;
use App\Models\NutritionSetting;
use Carbon\CarbonImmutable;

/**
 * Перехват следующего сообщения как значения интерактивной настройки.
 *
 * Когда пользователь нажал кнопку в /settings, ключ настройки лежит в
 * awaiting_setting. Ближайшее текстовое/числовое сообщение трактуется как
 * значение и обрабатывается здесь — до обычной логики HandleNumbers/HandleQuestion.
 */
class SettingInput
{
    /**
     * @param  array<string, mixed>  $update
     * @return bool true, если сообщение поглощено как значение настройки
     */
    public static function intercept(array $update): bool
    {
        $key = Settings::get('awaiting_setting');
        if (! is_string($key) || $key === '') {
            return false;
        }

        // Ожидание актуально, только пока запрос значения — последнее исходящее.
        // Если после него бот успел спросить что-то ещё (вес, шаги), число юзера
        // относится к тому запросу: сбрасываем устаревший awaiting и отдаём
        // сообщение обычной обработке.
        $lastOutKind = NutritionMessage::query()
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->value('kind');

        if ($lastOutKind !== 'setting_request') {
            self::clear();

            return false;
        }

        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);
        $text = trim((string) ($update['message']['text'] ?? ''));

        return match ($key) {
            // Подъём: 04:00–12:00.
            'wake_time' => self::applyTime($tg, $chatId, 'wake_time', $text, 240, 720, 'подъём', '☀️'),
            // Отбой: 20:00–23:59.
            'sleep_time' => self::applyTime($tg, $chatId, 'sleep_time', $text, 1200, 1439, 'отбой', '🌙'),
            'steps_target' => self::applySteps($tg, $chatId, $text),
            // Неизвестный ключ — сбрасываем и отдаём сообщение обычной обработке.
            default => self::abandon(),
        };
    }

    private static function applyTime(TelegramClient $tg, ?int $chatId, string $key, string $text, int $min, int $max, string $label, string $emoji): bool
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
        Settings::set($key, $value);
        self::clear();

        if ($key === 'sleep_time') {
            // Окна сегодняшних приёмов зависят от отбоя; на пустом дне отработает вхолостую.
            Planner::recalculate(CarbonImmutable::now('Europe/Moscow')->startOfDay());
        }

        $tg->send('Готово, '.$label.' теперь '.$value.' '.$emoji, chatId: $chatId);

        return true;
    }

    private static function applySteps(TelegramClient $tg, ?int $chatId, string $text): bool
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

        Settings::set('steps_target', $steps);
        self::clear();
        $tg->send('Новая цель: '.$steps.' шагов 👣', chatId: $chatId);

        return true;
    }

    private static function abandon(): bool
    {
        self::clear();

        return false;
    }

    /**
     * Сбрасывает awaiting_setting. Столбец value NOT NULL, поэтому «пусто» —
     * это отсутствие строки (Settings::get вернёт дефолт null).
     */
    private static function clear(): void
    {
        NutritionSetting::query()->where('key', 'awaiting_setting')->delete();
    }
}
