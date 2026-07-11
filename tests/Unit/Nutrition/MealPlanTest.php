<?php

use App\Support\Nutrition\MealPlan;
use Carbon\CarbonImmutable;

function mskDate(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-13 00:00', 'Europe/Moscow');
}

function defaults(): array
{
    return [
        'breakfast' => ['start' => '07:30', 'end' => '08:30'],
        'lunch' => ['start' => '11:00', 'end' => '12:30'],
        'snack' => ['start' => '14:40', 'end' => '16:10'],
        'dinner' => ['start' => '19:00', 'end' => '20:00'],
    ];
}

function pendingAll(): array
{
    $facts = [];
    foreach (MealPlan::TYPES as $t) {
        $facts[$t] = ['status' => 'pending', 'eaten_at' => null];
    }

    return $facts;
}

it('uses default windows when nothing eaten yet', function () {
    $w = MealPlan::windows(mskDate(), defaults(), pendingAll(), '23:00');
    expect($w['breakfast']['start']->format('H:i'))->toBe('07:30')
        ->and($w['lunch']['start']->format('H:i'))->toBe('11:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('20:00');
});

it('shifts next meal to eaten_at + 3..4h', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 20)];
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w)->not->toHaveKey('breakfast')
        ->and($w['lunch']['start']->format('H:i'))->toBe('11:20')
        ->and($w['lunch']['end']->format('H:i'))->toBe('12:20');
});

it('cascades late lunch into snack and dinner', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 0)];
    $facts['lunch'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(13, 30)];
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['snack']['start']->format('H:i'))->toBe('16:30')
        ->and($w['dinner']['start']->format('H:i'))->toBe('19:30');
});

it('recalculates from window end when a meal is skipped', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 0)];
    $facts['lunch'] = ['status' => 'skipped', 'eaten_at' => null];
    // окно обеда после завтрака в 8:00 = 11:00–12:00; пропуск → полдник от 12:00
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['snack']['start']->format('H:i'))->toBe('15:00');
});

it('clamps dinner to 2-3 hours before sleep', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(9, 0)];
    $facts['lunch'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(13, 0)];
    $facts['snack'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(17, 30)];
    // ужин 20:30–21:30, но сон 23:00 → end ≤ 21:00
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['dinner']['start']->format('H:i'))->toBe('20:30')
        ->and($w['dinner']['end']->format('H:i'))->toBe('21:00');
});

it('moves dinner start back when chain pushes it past sleep-2h', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(10, 0)];
    $facts['lunch'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(14, 30)];
    $facts['snack'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(18, 30)];
    // цепочка дала бы ужин 21:30+, сон 23:00 → окно 20:00–21:00
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['dinner']['start']->format('H:i'))->toBe('20:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('21:00');
});
