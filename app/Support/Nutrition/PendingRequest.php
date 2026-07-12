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
 * Ключи sent_events читаются в ДВУХ форматах: новый профиль-префиксный
 * `p{id}:{d}:...` и legacy без префикса `{d}:...`. Тик до Task 3 продолжает
 * писать legacy-ключи, поэтому оба формата учитываются, иначе у владельца
 * сломался бы контекст веса/шагов.
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

        return NutritionSentEvent::query()
            ->whereIn('event_key', [
                'p'.$profile->id.':'.$d.':'.$event,
                $d.':'.$event,
            ])
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
