<?php

namespace App\Support\Nutrition;

/**
 * Мелкие помощники для разбора входящих Telegram-апдейтов.
 */
class Tg
{
    /**
     * Идентификатор чата-источника апдейта (личка или группа), либо null.
     *
     * @param  array<string, mixed>  $update
     */
    public static function chatId(array $update): ?int
    {
        $id = $update['callback_query']['message']['chat']['id']
            ?? $update['message']['chat']['id']
            ?? null;

        return $id === null ? null : (int) $id;
    }
}
