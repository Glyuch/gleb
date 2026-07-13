<?php

namespace App\Support\Nutrition;

use App\Models\NutritionProfile;

/**
 * Резолвинг профиля-отправителя входящего апдейта по Telegram from.id.
 *
 * Единая точка входа мультипользовательской обработки: по числовому id
 * отправителя (личка или колбэк) находит его профиль и обновляет last_seen_at.
 * Возвращает null, если отправитель неизвестен (нет профиля) — тогда
 * вызывающий решает про инвайт-подсказку / молчание.
 */
class ProfileContext
{
    public static function resolve(array $update): ?NutritionProfile
    {
        $fromId = $update['callback_query']['from']['id']
            ?? $update['message']['from']['id']
            ?? null;

        if ($fromId === null) {
            return null;
        }

        $profile = NutritionProfile::query()
            ->where('telegram_user_id', (int) $fromId)
            ->first();

        if ($profile !== null) {
            $profile->forceFill(['last_seen_at' => $profile->now()])->save();
        }

        return $profile;
    }
}
