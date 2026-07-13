<?php

namespace App\Support\Nutrition;

use DateTimeZone;

/**
 * Разбор свободного ввода часового пояса: готовый IANA-идентификатор, смещение
 * (+2 / -5 / +5:30 / +0530) либо частый город (рус+англ). Расширяемая карта
 * городов держится в одном месте — CITIES.
 */
class Timezone
{
    /** Частые города → IANA (ключи в нижнем регистре, пробелы схлопнуты). */
    public const CITIES = [
        'москва' => 'Europe/Moscow', 'мск' => 'Europe/Moscow', 'moscow' => 'Europe/Moscow',
        'калининград' => 'Europe/Kaliningrad', 'kaliningrad' => 'Europe/Kaliningrad',
        'самара' => 'Europe/Samara', 'samara' => 'Europe/Samara',
        'екатеринбург' => 'Asia/Yekaterinburg', 'екб' => 'Asia/Yekaterinburg', 'yekaterinburg' => 'Asia/Yekaterinburg',
        'омск' => 'Asia/Omsk', 'omsk' => 'Asia/Omsk',
        'новосибирск' => 'Asia/Novosibirsk', 'novosibirsk' => 'Asia/Novosibirsk',
        'красноярск' => 'Asia/Krasnoyarsk', 'krasnoyarsk' => 'Asia/Krasnoyarsk',
        'иркутск' => 'Asia/Irkutsk', 'irkutsk' => 'Asia/Irkutsk',
        'владивосток' => 'Asia/Vladivostok', 'vladivostok' => 'Asia/Vladivostok',
        'ереван' => 'Asia/Yerevan', 'yerevan' => 'Asia/Yerevan',
        'тбилиси' => 'Asia/Tbilisi', 'tbilisi' => 'Asia/Tbilisi',
        'баку' => 'Asia/Baku', 'baku' => 'Asia/Baku',
        'алматы' => 'Asia/Almaty', 'алма-ата' => 'Asia/Almaty', 'астана' => 'Asia/Almaty', 'almaty' => 'Asia/Almaty', 'astana' => 'Asia/Almaty',
        'ташкент' => 'Asia/Tashkent', 'tashkent' => 'Asia/Tashkent',
        'бишкек' => 'Asia/Bishkek', 'bishkek' => 'Asia/Bishkek',
        'минск' => 'Europe/Minsk', 'minsk' => 'Europe/Minsk',
        'киев' => 'Europe/Kyiv', 'kyiv' => 'Europe/Kyiv', 'kiev' => 'Europe/Kyiv',
        'белград' => 'Europe/Belgrade', 'belgrade' => 'Europe/Belgrade',
        'берлин' => 'Europe/Berlin', 'berlin' => 'Europe/Berlin',
        'париж' => 'Europe/Paris', 'paris' => 'Europe/Paris',
        'лондон' => 'Europe/London', 'london' => 'Europe/London',
        'лиссабон' => 'Europe/Lisbon', 'lisbon' => 'Europe/Lisbon',
        'стамбул' => 'Europe/Istanbul', 'istanbul' => 'Europe/Istanbul',
        'дубай' => 'Asia/Dubai', 'dubai' => 'Asia/Dubai',
        'бангкок' => 'Asia/Bangkok', 'пхукет' => 'Asia/Bangkok', 'bangkok' => 'Asia/Bangkok', 'phuket' => 'Asia/Bangkok',
        'бали' => 'Asia/Makassar', 'денпасар' => 'Asia/Makassar', 'bali' => 'Asia/Makassar', 'denpasar' => 'Asia/Makassar',
        'гонконг' => 'Asia/Hong_Kong', 'hong kong' => 'Asia/Hong_Kong', 'hongkong' => 'Asia/Hong_Kong',
        'токио' => 'Asia/Tokyo', 'tokyo' => 'Asia/Tokyo',
        'нью-йорк' => 'America/New_York', 'нью йорк' => 'America/New_York', 'new york' => 'America/New_York',
        'лос-анджелес' => 'America/Los_Angeles', 'los angeles' => 'America/Los_Angeles',
    ];

    /**
     * Возвращает валидный часовой пояс (IANA или «+HH:MM») либо null.
     */
    public static function parse(string $input): ?string
    {
        $trim = trim($input);
        if ($trim === '') {
            return null;
        }

        // 1. Готовый IANA-идентификатор (как есть).
        if (in_array($trim, DateTimeZone::listIdentifiers(), true)) {
            return $trim;
        }

        // 2. Смещение → нормализуем в «+HH:MM».
        $offset = self::parseOffset($trim);
        if ($offset !== null) {
            return $offset;
        }

        // 3. Город (регистронезависимо, пробелы схлопнуты).
        $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $trim));

        return self::CITIES[$key] ?? null;
    }

    /**
     * Смещение +2/-5/+5:30/+0530 → «+HH:MM» (диапазон часов 0–14) либо null.
     */
    private static function parseOffset(string $input): ?string
    {
        if (! preg_match('/^([+-])(\d{1,2})(?::?([0-5]\d))?$/', $input, $m)) {
            return null;
        }

        $hours = (int) $m[2];
        $minutes = isset($m[3]) ? (int) $m[3] : 0;

        if ($hours > 14 || ($hours === 14 && $minutes > 0)) {
            return null;
        }

        return sprintf('%s%02d:%02d', $m[1], $hours, $minutes);
    }
}
