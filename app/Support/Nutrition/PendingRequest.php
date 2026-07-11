<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMetric;
use App\Models\NutritionSentEvent;
use Carbon\CarbonImmutable;

/**
 * Контекст ожидаемого ввода на основе фактически отправленных за сегодня
 * запросов (sent_events), а не последнего исходящего сообщения.
 *
 * Нужно, потому что в один прогон тика может уйти пачка сообщений
 * (weight_request → greeting → reminder), из-за чего lastOutKind перестаёт
 * указывать на актуальный запрос. sent_event переживает такой клоббер.
 */
class PendingRequest
{
    /**
     * Сегодня был отправлен запрос веса И вес за сегодня ещё не записан.
     */
    public static function expectsWeight(CarbonImmutable $now): bool
    {
        return self::wasRequested($now, 'weight_request')
            && ! self::hasMetric($now, 'weight');
    }

    /**
     * Сегодня был отправлен запрос метрик И шаги за сегодня ещё не записаны.
     */
    public static function expectsMetrics(CarbonImmutable $now): bool
    {
        return self::wasRequested($now, 'metrics_request')
            && ! self::hasMetric($now, 'steps');
    }

    private static function wasRequested(CarbonImmutable $now, string $event): bool
    {
        return NutritionSentEvent::query()
            ->where('event_key', $now->format('Y-m-d').':'.$event)
            ->exists();
    }

    private static function hasMetric(CarbonImmutable $now, string $type): bool
    {
        return NutritionMetric::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', $type)
            ->exists();
    }
}
