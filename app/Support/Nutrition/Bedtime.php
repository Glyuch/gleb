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
     * Разбирает свободный ввод отбоя: «23:00», «00:30», «12», «в 12», «в 12 ночи»,
     * «24:00», «полночь». Полдень трактуется как абсурдный отбой: «полдень»,
     * «12 дня», «в 12 дня» → reask.
     *
     * @return array{status: 'ok', value: string}|array{status: 'reask'}|array{status: 'invalid'}
     */
    public static function fromText(string $text): array
    {
        $lower = mb_strtolower($text);

        // Явная полночь словом — валидный отбой.
        if (preg_match('/полноч|полуноч/u', $lower)) {
            return ['status' => 'ok', 'value' => '00:00'];
        }

        // Явный полдень словом — абсурдный отбой, переспрашиваем.
        if (preg_match('/полдень|полудн|полудень/u', $lower)) {
            return ['status' => 'reask'];
        }

        // Дневной квалификатор при «12»: «12 дня», «в 12 дня» — это полдень, не полночь.
        $noonIsDaytime = (bool) preg_match('/дня|днём|днем|дневн/u', $lower);

        if (preg_match('/(\d{1,2}):(\d{2})/', $text, $m)) {
            return self::fromHm((int) $m[1], (int) $m[2], $noonIsDaytime);
        }

        if (preg_match('/(\d{1,2})/', $text, $m)) {
            return self::fromHm((int) $m[1], 0, $noonIsDaytime);
        }

        return ['status' => 'invalid'];
    }

    /**
     * Нормализует уже распарсенные часы/минуты отбоя.
     *
     * @param  bool  $noonIsDaytime  «12» пришло с дневным квалификатором («12 дня») —
     *                               это полдень (абсурдный отбой), а не полночь.
     * @return array{status: 'ok', value: string}|array{status: 'reask'}|array{status: 'invalid'}
     */
    public static function fromHm(int $hours, int $minutes, bool $noonIsDaytime = false): array
    {
        // «24:00» — распространённая запись полуночи.
        if ($hours === 24 && $minutes === 0) {
            $hours = 0;
        }

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return ['status' => 'invalid'];
        }

        // «12» под отбоём: без дневного квалификатора — полночь (12:xx → 00:xx);
        // «12 дня»/«в 12 дня» — полдень, абсурдный отбой → переспрос.
        if ($hours === 12) {
            if ($noonIsDaytime) {
                return ['status' => 'reask'];
            }
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
