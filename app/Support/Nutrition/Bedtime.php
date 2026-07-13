<?php

namespace App\Support\Nutrition;

/**
 * Разбор и валидация времени отбоя (sleep_time) из свободного ввода.
 *
 * Люди под «ложусь в 12» почти всегда имеют в виду полночь, а не полдень, поэтому
 * любой час «12» трактуется как 00:xx. Если распарсенный отбой попадает в явно
 * дневной диапазон (примерно 04:00–19:59) — это почти наверняка опечатка (напр.
 * «14:00»): такой отбой НЕ принимается, вызывающий код переспрашивает. Валидным
 * считается ночной/вечерний отбой: 00:00–03:59 или 20:00–23:59.
 *
 * Только для отбоя. Подъём (wake_time) этой эвристикой не трогается — подъём в
 * 09:00 совершенно валиден.
 */
class Bedtime
{
    /** Начало дневного диапазона (включительно), минуты от полуночи: 04:00. */
    private const DAY_FROM = 240;

    /** Конец дневного диапазона (исключительно), минуты от полуночи: 20:00. */
    private const DAY_TO = 1200;

    /**
     * Разбирает свободный ввод отбоя: «23:00», «00:30», «12», «в 12», «в 12 ночи».
     *
     * @return array{status: 'ok', value: string}|array{status: 'reask'}|array{status: 'invalid'}
     */
    public static function fromText(string $text): array
    {
        if (preg_match('/(\d{1,2}):(\d{2})/', $text, $m)) {
            return self::fromHm((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/(\d{1,2})/', $text, $m)) {
            return self::fromHm((int) $m[1], 0);
        }

        return ['status' => 'invalid'];
    }

    /**
     * Нормализует уже распарсенные часы/минуты отбоя.
     *
     * @return array{status: 'ok', value: string}|array{status: 'reask'}|array{status: 'invalid'}
     */
    public static function fromHm(int $hours, int $minutes): array
    {
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return ['status' => 'invalid'];
        }

        // «12» (полдень) под отбоем — это полночь: 12:xx → 00:xx.
        if ($hours === 12) {
            $hours = 0;
        }

        $total = $hours * 60 + $minutes;

        // Явно дневное значение (кроме уже переведённого «12») — вероятная опечатка.
        if ($total >= self::DAY_FROM && $total < self::DAY_TO) {
            return ['status' => 'reask'];
        }

        return ['status' => 'ok', 'value' => sprintf('%02d:%02d', $hours, $minutes)];
    }
}
