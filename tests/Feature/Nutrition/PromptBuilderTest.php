<?php

use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Support\Nutrition\PromptBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->profile = nutritionProfile();
});

it('builds a system prompt with the persona, knowledge base and the profile ai_profile', function () {
    $this->profile->update(['ai_profile' => 'Глеб, цель — снижение веса. Липиды — зона внимания.']);

    $system = PromptBuilder::system($this->profile);

    expect($system)->toContain('TriDaily')
        ->and($system)->toContain('Перекусов НЕТ никогда')
        ->and($system)->toContain('# Профиль клиента')
        ->and($system)->toContain('Липиды — зона внимания');
});

it('personalizes the persona and day context with the profile name, not the owner', function () {
    $andrey = nutritionProfile(['telegram_user_id' => 333, 'name' => 'Андрей', 'is_admin' => false]);

    $system = PromptBuilder::system($andrey);
    $context = PromptBuilder::dayContext($andrey, CarbonImmutable::parse('2026-07-13', 'Europe/Moscow'));

    expect($system)->toContain('Андрей')
        ->and($system)->not->toContain('Глеб')
        ->and($context)->toContain('Андрей')
        ->and($context)->not->toContain('Глеб');
});

it('falls back to «клиент» in the persona when the name is empty', function () {
    $nameless = nutritionProfile(['telegram_user_id' => 444, 'name' => '', 'is_admin' => false]);

    expect(PromptBuilder::system($nameless))->toContain('клиент');
});

it('includes meal labels and weight metrics in the day context', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    $this->travelTo($date);

    $this->profile->update(['phase' => 'program', 'program_started_on' => '2026-07-01']);

    NutritionMeal::query()->create([
        'profile_id' => $this->profile->id,
        'date' => $date->format('Y-m-d'),
        'type' => 'lunch',
        'window_start' => $date->setTime(11, 0)->format('Y-m-d H:i:s'),
        'window_end' => $date->setTime(12, 30)->format('Y-m-d H:i:s'),
        'eaten_at' => $date->setTime(11, 40)->format('Y-m-d H:i:s'),
        'ai_feedback' => 'Отличная тарелка!',
        'status' => 'eaten',
    ]);

    NutritionMetric::query()->create([
        'profile_id' => $this->profile->id,
        'date' => $date->format('Y-m-d'),
        'type' => 'weight',
        'value' => 82.4,
    ]);

    $context = PromptBuilder::dayContext($this->profile, $date);

    expect($context)->toContain('Обед')
        ->and($context)->toContain('82.4')
        ->and($context)->toContain('11:40')
        ->and($context)->toContain('Отличная тарелка!')
        ->and($context)->toContain('День 13 программы');
});

it('falls back to maintenance phase when the program is not started', function () {
    $this->profile->update(['phase' => 'maintenance']);

    $context = PromptBuilder::dayContext($this->profile, CarbonImmutable::parse('2026-07-13', 'Europe/Moscow'));

    expect($context)->toContain('Режим поддержки');
});

it('isolates day context data between profiles', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    $other = nutritionProfile(['telegram_user_id' => 222, 'is_admin' => false]);

    NutritionMetric::query()->create([
        'profile_id' => $other->id, 'date' => $date->format('Y-m-d'), 'type' => 'weight', 'value' => 99.9,
    ]);

    $context = PromptBuilder::dayContext($this->profile, $date);

    expect($context)->not->toContain('99.9');
});
