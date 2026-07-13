<?php

namespace App\Support\Nutrition;

use App\Models\NutritionProfile;

/**
 * Детерминированное обращение к клиенту по имени. Страховка поверх промпта: даже
 * если ИИ не начал ответ с имени, код гарантирует «{Имя}, …» в начале сообщения.
 * Имя берётся из профиля; при пустом имени обращение не добавляется (без «клиент,»).
 */
class Address
{
    /**
     * Префикс-обращение «{Имя}, » либо '' если имя пустое.
     */
    public static function prefix(NutritionProfile $profile): string
    {
        $name = self::name($profile);

        return $name === '' ? '' : $name.', ';
    }

    /**
     * Гарантирует обращение по имени в начале текста, отправляемого клиенту.
     * — имя пустое → текст без изменений;
     * — текст уже начинается с имени (без учёта регистра) → не дублируем;
     * — иначе префиксуем «{Имя}, » и понижаем регистр первой буквы исходного текста,
     *   если она кириллическая заглавная (чтобы не было «Глеб, Отлично»); эмодзи,
     *   латиницу, цифры и пунктуацию не трогаем.
     */
    public static function ensure(NutritionProfile $profile, string $text): string
    {
        $name = self::name($profile);
        if ($name === '') {
            return $text;
        }

        $body = ltrim($text);
        if ($body === '') {
            return $text;
        }

        // Уже начинается с имени — не дублируем.
        if (mb_strtolower(mb_substr($body, 0, mb_strlen($name))) === mb_strtolower($name)) {
            return $text;
        }

        return $name.', '.self::lowerFirst($body);
    }

    private static function name(NutritionProfile $profile): string
    {
        return trim((string) $profile->name);
    }

    /**
     * Понижает регистр первой буквы, только если это кириллическая заглавная
     * (А–Я, Ё). Прочее не трогаем, чтобы не ломать эмодзи/латинские аббревиатуры.
     */
    private static function lowerFirst(string $text): string
    {
        $first = mb_substr($text, 0, 1);

        if (preg_match('/^[А-ЯЁ]$/u', $first)) {
            return mb_strtolower($first).mb_substr($text, 1);
        }

        return $text;
    }
}
