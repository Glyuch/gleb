<?php

use App\Models\NutritionSentEvent;
use App\Support\Nutrition\Settings;

it('returns defaults when setting is absent', function () {
    expect(Settings::get('steps_target'))->toBe(7000)
        ->and(Settings::get('default_windows'))->toHaveKey('breakfast');
});

it('stores and reads back values', function () {
    Settings::set('steps_target', 9000);
    expect(Settings::get('steps_target'))->toBe(9000);
});

it('runs sent-event callback only once per key', function () {
    $runs = 0;
    NutritionSentEvent::once('k1', function () use (&$runs) {
        $runs++;
    });
    NutritionSentEvent::once('k1', function () use (&$runs) {
        $runs++;
    });
    expect($runs)->toBe(1);
});
