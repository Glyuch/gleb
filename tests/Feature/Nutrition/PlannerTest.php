<?php

use App\Models\NutritionMeal;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;

it('ensures four meals with default windows', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);
    Planner::ensureDay($date); // идемпотентно

    expect(NutritionMeal::count())->toBe(4)
        ->and(NutritionMeal::where('type', 'lunch')->first()->window_start->format('H:i'))->toBe('11:00');
});

it('recalculates downstream windows after eating', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);
    $breakfast = NutritionMeal::where('type', 'breakfast')->first();

    Planner::markEaten($breakfast, $date->setTime(8, 20), null, 'ok');

    expect(NutritionMeal::where('type', 'lunch')->first()->window_start->format('H:i'))->toBe('11:20');
});

it('finds current meal by time', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);

    $meal = Planner::currentMeal($date->setTime(11, 30));
    expect($meal->type)->toBe('lunch');
});

it('marks overdue meals missed and shifts the rest', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);
    NutritionMeal::where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => $date->setTime(8, 0)]);
    Planner::recalculate($date); // обед 11:00–12:00

    Planner::markMissed($date->setTime(13, 45)); // 12:00 + 90 мин прошло

    expect(NutritionMeal::where('type', 'lunch')->first()->status)->toBe('missed')
        ->and(NutritionMeal::where('type', 'snack')->first()->window_start->format('H:i'))->toBe('15:00');
});
