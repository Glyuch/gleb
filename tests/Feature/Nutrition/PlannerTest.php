<?php

use App\Models\NutritionMeal;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->profile = nutritionProfile();
});

it('ensures four meals with default windows', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($this->profile, $date);
    Planner::ensureDay($this->profile, $date); // идемпотентно

    expect(NutritionMeal::count())->toBe(4)
        ->and(NutritionMeal::where('type', 'lunch')->first()->window_start->format('H:i'))->toBe('11:00');
});

it('recalculates downstream windows after eating', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($this->profile, $date);
    $breakfast = NutritionMeal::where('type', 'breakfast')->first();

    Planner::markEaten($this->profile, $breakfast, $date->setTime(8, 20), null, 'ok');

    expect(NutritionMeal::where('type', 'lunch')->first()->window_start->format('H:i'))->toBe('11:20');
});

it('finds current meal by time', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($this->profile, $date);

    $meal = Planner::currentMeal($this->profile, $date->setTime(11, 30));
    expect($meal->type)->toBe('lunch');
});

it('marks overdue meals missed and shifts the rest', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($this->profile, $date);
    NutritionMeal::where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => $date->setTime(8, 0)]);
    Planner::recalculate($this->profile, $date); // обед 11:00–12:00

    Planner::markMissed($this->profile, $date->setTime(13, 45)); // 12:00 + 90 мин прошло

    expect(NutritionMeal::where('type', 'lunch')->first()->status)->toBe('missed')
        ->and(NutritionMeal::where('type', 'snack')->first()->window_start->format('H:i'))->toBe('15:00');
});
