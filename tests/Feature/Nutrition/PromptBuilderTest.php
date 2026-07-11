<?php

use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Support\Nutrition\PromptBuilder;
use App\Support\Nutrition\Settings;
use Carbon\CarbonImmutable;

it('builds a system prompt with the persona and the knowledge base', function () {
    $system = PromptBuilder::system();

    expect($system)->toContain('TriDaily')
        ->and($system)->toContain('Перекусов НЕТ никогда');
});

it('includes meal labels and weight metrics in the day context', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    $this->travelTo($date);

    Settings::set('phase', 'program');
    Settings::set('program_started_on', '2026-07-01');

    NutritionMeal::query()->create([
        'date' => $date->format('Y-m-d'),
        'type' => 'lunch',
        'window_start' => $date->setTime(11, 0)->format('Y-m-d H:i:s'),
        'window_end' => $date->setTime(12, 30)->format('Y-m-d H:i:s'),
        'eaten_at' => $date->setTime(11, 40)->format('Y-m-d H:i:s'),
        'ai_feedback' => 'Отличная тарелка!',
        'status' => 'eaten',
    ]);

    NutritionMetric::query()->create([
        'date' => $date->format('Y-m-d'),
        'type' => 'weight',
        'value' => 82.4,
    ]);

    $context = PromptBuilder::dayContext($date);

    expect($context)->toContain('Обед')
        ->and($context)->toContain('82.4')
        ->and($context)->toContain('11:40')
        ->and($context)->toContain('Отличная тарелка!')
        ->and($context)->toContain('День 13 программы');
});

it('falls back to maintenance phase when the program is not started', function () {
    Settings::set('phase', 'maintenance');

    $context = PromptBuilder::dayContext(CarbonImmutable::parse('2026-07-13', 'Europe/Moscow'));

    expect($context)->toContain('Режим поддержки');
});
