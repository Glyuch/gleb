<?php

use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Models\NutritionSentEvent;
use App\Support\Nutrition\PendingRequest;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;

it('isolates meals, metrics and pending-request context between two profiles', function () {
    $now = CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow');
    $this->travelTo($now);

    $a = nutritionProfile(['telegram_user_id' => 111]);
    $b = nutritionProfile(['telegram_user_id' => 222, 'is_admin' => false]);

    // Оба профиля получают свои приёмы на ту же дату (per-profile unique позволяет).
    Planner::ensureDay($a, $now);
    Planner::ensureDay($b, $now);

    expect(NutritionMeal::where('profile_id', $a->id)->count())->toBe(4)
        ->and(NutritionMeal::where('profile_id', $b->id)->count())->toBe(4);

    // A отмечает завтрак съеденным — на B это не влияет.
    $aBreakfast = NutritionMeal::where('profile_id', $a->id)->where('type', 'breakfast')->first();
    Planner::markEaten($a, $aBreakfast, $now->setTime(8, 0), null, 'реакция для A');

    expect(NutritionMeal::where('profile_id', $a->id)->where('type', 'breakfast')->value('status'))->toBe('eaten')
        ->and(NutritionMeal::where('profile_id', $b->id)->where('type', 'breakfast')->value('status'))->toBe('pending');

    // currentMeal видит только приёмы своего профиля.
    expect(Planner::currentMeal($a, $now->setTime(11, 30))->profile_id)->toBe($a->id)
        ->and(Planner::currentMeal($b, $now->setTime(11, 30))->profile_id)->toBe($b->id);

    // Метрики изолированы: вес есть только у A.
    NutritionMetric::create(['profile_id' => $a->id, 'date' => $now->format('Y-m-d'), 'type' => 'weight', 'value' => 80]);

    // PendingRequest: профиль-префиксный запрос веса выставлен только для B.
    NutritionSentEvent::create([
        'event_key' => 'p'.$b->id.':'.$now->format('Y-m-d').':weight_request',
        'sent_at' => now(),
    ]);

    // B ждёт вес (запрос есть, метрики нет); A — нет (запроса нет + вес уже записан).
    expect(PendingRequest::expectsWeight($b, $now))->toBeTrue()
        ->and(PendingRequest::expectsWeight($a, $now))->toBeFalse();
});
