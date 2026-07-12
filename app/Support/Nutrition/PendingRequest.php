<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use App\Models\NutritionSentEvent;
use Carbon\CarbonImmutable;

/**
 * Контекст ожидаемого ввода на основе фактически отправленных за сегодня
 * запросов (sent_events), а не последнего исходящего сообщения.
 *
 * Нужно, потому что в один прогон тика может уйти пачка сообщений
 * (weight_request → greeting → reminder), из-за чего lastOutKind перестаёт
 * указывать на актуальный запрос. sent_event переживает такой клоббер.
 *
 * Ключи sent_events читаются профиль-префиксными: `p{id}:{d}:...`. Для admin-
 * профиля (владельца) ПЕРЕХОДНО учитывается и legacy-ключ без префикса `{d}:...`
 * — это сегодняшние запросы, отправленные тиком до перехода на префиксы (Task 3).
 * Для остальных профилей legacy-ключи не матчатся никогда (иначе контекст одного
 * пользователя протёк бы в другого). Можно удалить legacy-ветку после 2026-07-14.
 */
class PendingRequest
{
    /**
     * Сегодня был отправлен запрос веса И вес за сегодня ещё не записан.
     */
    public static function expectsWeight(NutritionProfile $profile, CarbonImmutable $now): bool
    {
        return self::wasRequested($profile, $now, 'weight_request')
            && ! self::hasMetric($profile, $now, 'weight');
    }

    /**
     * Сегодня был отправлен запрос метрик И шаги за сегодня ещё не записаны.
     */
    public static function expectsMetrics(NutritionProfile $profile, CarbonImmutable $now): bool
    {
        return self::wasRequested($profile, $now, 'metrics_request')
            && ! self::hasMetric($profile, $now, 'steps');
    }

    private static function wasRequested(NutritionProfile $profile, CarbonImmutable $now, string $event): bool
    {
        $d = $now->format('Y-m-d');

        $keys = ['p'.$profile->id.':'.$d.':'.$event];

        // Переходно и ТОЛЬКО для admin: legacy-ключ без префикса. Удалить после 2026-07-14.
        if ($profile->is_admin) {
            $keys[] = $d.':'.$event;
        }

        return NutritionSentEvent::query()
            ->whereIn('event_key', $keys)
            ->exists();
    }

    private static function hasMetric(NutritionProfile $profile, CarbonImmutable $now, string $type): bool
    {
        return NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', $type)
            ->exists();
    }
}
