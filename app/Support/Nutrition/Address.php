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

        // Уже начинается с имени — не дублируем. Но только по границе слова:
        // «Ян, молодец» — уже обратились, а «Янтарная кислота» — нет (имя внутри слова).
        if (mb_strtolower(mb_substr($body, 0, mb_strlen($name))) === mb_strtolower($name)) {
            $after = mb_substr($body, mb_strlen($name), 1);
            if ($after === '' || ! preg_match('/^\p{L}$/u', $after)) {
                return $text;
            }
        }

        return $name.', '.self::lowerFirst($body);
    }

    private static function name(NutritionProfile $profile): string
    {
        return trim((string) $profile->name);
    }

    /**
     * Понижает регистр первой буквы, только если первое слово — обычное с одной
     * заглавной («Отлично» → «отлично»). Не трогаем, если первый символ не
     * кириллическая заглавная (эмодзи/латиница/цифры), либо первое слово целиком
     * в верхнем регистре (аббревиатура: «ЗОЖ», «АД»), либо это одиночный инициал.
     */
    private static function lowerFirst(string $text): string
    {
        $first = mb_substr($text, 0, 1);

        if (! preg_match('/^[А-ЯЁ]$/u', $first)) {
            return $text;
        }

        // Первое слово — ведущая последовательность букв.
        preg_match('/^\p{L}+/u', $text, $m);
        $word = $m[0] ?? $first;

        // Одиночный инициал или аббревиатура целиком капсом — не трогаем.
        if (mb_strlen($word) === 1 || mb_strtoupper($word) === $word) {
            return $text;
        }

        return mb_strtolower($first).mb_substr($text, 1);
    }
}
