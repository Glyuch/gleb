<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
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

        $tg = app(TelegramClient::class);
        $text = trim((string) ($update['message']['text'] ?? ''));

        return match ($key) {
            // Подъём: 04:00–12:00.
            'wake_time' => self::applyTime($tg, 'wake_time', $text, 240, 720, 'подъём', '☀️'),
            // Отбой: 20:00–23:59.
            'sleep_time' => self::applyTime($tg, 'sleep_time', $text, 1200, 1439, 'отбой', '🌙'),
            'steps_target' => self::applySteps($tg, $text),
            // Неизвестный ключ — сбрасываем и отдаём сообщение обычной обработке.
            default => self::abandon(),
        };
    }

    private static function applyTime(TelegramClient $tg, string $key, string $text, int $min, int $max, string $label, string $emoji): bool
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m)) {
            $tg->send('Не понял время. Пришли в формате ЧЧ:ММ, например 07:30', null, 'setting_request');

            return true;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            $tg->send('Такого времени не бывает. Пришли в формате ЧЧ:ММ, например 07:30', null, 'setting_request');

            return true;
        }

        $total = $hours * 60 + $minutes;
        if ($total < $min || $total > $max) {
            $range = sprintf('%02d:%02d–%02d:%02d', intdiv($min, 60), $min % 60, intdiv($max, 60), $max % 60);
            $tg->send('Время вне разумного диапазона ('.$range.'). Пришли ещё раз в формате ЧЧ:ММ.', null, 'setting_request');

            return true;
        }

        $value = sprintf('%02d:%02d', $hours, $minutes);
        Settings::set($key, $value);
        self::clear();

        if ($key === 'sleep_time') {
            self::recalculateToday();
        }

        $tg->send('Готово, '.$label.' теперь '.$value.' '.$emoji);

        return true;
    }

    private static function applySteps(TelegramClient $tg, string $text): bool
    {
        if (! preg_match('/^\d+$/', $text)) {
            $tg->send('Пришли число шагов в день (3000–30000)', null, 'setting_request');

            return true;
        }

        $steps = (int) $text;
        if ($steps < 3000 || $steps > 30000) {
            $tg->send('Цель вне диапазона. Пришли число от 3000 до 30000.', null, 'setting_request');

            return true;
        }

        Settings::set('steps_target', $steps);
        self::clear();
        $tg->send('Новая цель: '.$steps.' шагов 👣');

        return true;
    }

    private static function abandon(): bool
    {
        self::clear();

        return false;
    }

    /**
     * Пересчитывает окна приёмов на сегодня, только если строки приёмов уже созданы
     * (не плодим их раньше времени).
     */
    private static function recalculateToday(): void
    {
        $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();

        $hasMeals = NutritionMeal::query()
            ->whereDate('date', $today->format('Y-m-d'))
            ->exists();

        if ($hasMeals) {
            Planner::recalculate($today);
        }
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
