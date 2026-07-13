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

it('recalculates snack after late lunch, dinner stays default', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 0)];
    $facts['lunch'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(13, 30)];
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    // полдник — первый pending после факта: 16:30–17:30; ужин остаётся дефолтным
    expect($w['snack']['start']->format('H:i'))->toBe('16:30')
        ->and($w['dinner']['start']->format('H:i'))->toBe('19:00');
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

it('keeps default windows for pendings after the first recalculated one', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 20)];
    // пересчитывается только обед (первый pending после факта); полдник и ужин — дефолт
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['lunch']['start']->format('H:i'))->toBe('11:20')
        ->and($w['lunch']['end']->format('H:i'))->toBe('12:20')
        ->and($w['snack']['start']->format('H:i'))->toBe('14:40')
        ->and($w['snack']['end']->format('H:i'))->toBe('16:10')
        ->and($w['dinner']['start']->format('H:i'))->toBe('19:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('20:00');
});

it('does not drag chain through pendings after a very late breakfast', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(16, 0)];
    // обед 19:00–20:00 (пересчитан); полдник и ужин остаются дефолтными —
    // окно полдника в прошлом допустимо, tick пометит missed и пересчитает
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['lunch']['start']->format('H:i'))->toBe('19:00')
        ->and($w['lunch']['end']->format('H:i'))->toBe('20:00')
        ->and($w['snack']['start']->format('H:i'))->toBe('14:40')
        ->and($w['snack']['end']->format('H:i'))->toBe('16:10')
        ->and($w['dinner']['start']->format('H:i'))->toBe('19:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('20:00');
});

it('recalculates only first pending after skipped meal, rest stay default', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 0)];
    $facts['lunch'] = ['status' => 'skipped', 'eaten_at' => null];
    // обед skipped (окно 11:00–12:00) → полдник 15:00–16:00; ужин — дефолт
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['snack']['start']->format('H:i'))->toBe('15:00')
        ->and($w['snack']['end']->format('H:i'))->toBe('16:00')
        ->and($w['dinner']['start']->format('H:i'))->toBe('19:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('20:00');
});

it('never builds inverted windows when sleep_time is absurd (noon)', function () {
    // Отбой в полдень (12:00) при подъёме в 09:00 — перевёрнутый ввод. Раньше
    // dinner-клэмп «конец ≤ sleep−2ч» утаскивал ужин на 09:00–10:00 (перед завтраком).
    $wakeDefaults = [
        'breakfast' => ['start' => '09:30', 'end' => '10:30'],
        'lunch' => ['start' => '11:00', 'end' => '12:30'],
        'snack' => ['start' => '14:40', 'end' => '16:10'],
        'dinner' => ['start' => '19:00', 'end' => '20:00'],
    ];
    $w = MealPlan::windows(mskDate(), $wakeDefaults, pendingAll(), '12:00');

    // Все окна на месте и в строгом хронологическом порядке — никакого «ужина в 9 утра».
    expect($w)->toHaveKeys(['breakfast', 'lunch', 'snack', 'dinner']);
    $bStart = $w['breakfast']['start'];
    expect($w['lunch']['start']->greaterThan($bStart))->toBeTrue()
        ->and($w['snack']['start']->greaterThan($w['lunch']['start']))->toBeTrue()
        ->and($w['dinner']['start']->greaterThan($w['snack']['start']))->toBeTrue()
        // Ужин физически не может начаться раньше конца полдника.
        ->and($w['dinner']['start']->greaterThanOrEqualTo($w['snack']['end']))->toBeTrue()
        ->and($w['breakfast']['start']->format('H:i'))->toBe('09:30');
});

it('leaves a normal day untouched (ordering invariant for Gleb-like profile)', function () {
    // Инвариант: нормальный профиль (подъём ~08:00, отбой 23:00) — окна прежние.
    $normalDefaults = [
        'breakfast' => ['start' => '08:30', 'end' => '09:30'],
        'lunch' => ['start' => '11:00', 'end' => '12:30'],
        'snack' => ['start' => '14:40', 'end' => '16:10'],
        'dinner' => ['start' => '19:00', 'end' => '20:00'],
    ];
    $w = MealPlan::windows(mskDate(), $normalDefaults, pendingAll(), '23:00');

    foreach ($normalDefaults as $type => $win) {
        expect($w[$type]['start']->format('H:i'))->toBe($win['start'])
            ->and($w[$type]['end']->format('H:i'))->toBe($win['end']);
    }
});

it('keeps dinner in the evening for a past-midnight bedtime (00:00), not dragged to afternoon', function () {
    // Отбой 00:00 (полночь) относится к следующим суткам. До фикса «sleep − 2ч»
    // уезжало на прошлый день, ужин утягивался к концу полдника → фиксированные
    // 16:10–17:10. Теперь ужин остаётся вечерним (дефолт 19:00–20:00 укладывается
    // в границу «≤ 22:00»).
    $w = MealPlan::windows(mskDate(), defaults(), pendingAll(), '00:00');
    expect($w['dinner']['start']->format('H:i'))->toBe('19:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('20:00')
        ->and($w['dinner']['start']->format('H:i'))->not->toBe('16:10');
});

it('clamps dinner to 2h before a past-midnight bedtime for a late-dinner profile', function () {
    // «Сова» с поздним дефолтным ужином (21:00–23:00) и отбоем в полночь: граница
    // «конец ≤ sleep−2ч» = 22:00 (на следующих сутках), окно ужина → 21:00–22:00.
    $owlDefaults = [
        'breakfast' => ['start' => '09:30', 'end' => '10:30'],
        'lunch' => ['start' => '13:00', 'end' => '14:30'],
        'snack' => ['start' => '17:00', 'end' => '18:30'],
        'dinner' => ['start' => '21:00', 'end' => '23:00'],
    ];
    $w = MealPlan::windows(mskDate(), $owlDefaults, pendingAll(), '00:00');
    expect($w['dinner']['start']->format('H:i'))->toBe('21:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('22:00');
});

it('keeps dinner in the evening for a past-midnight bedtime at 02:00', function () {
    // Отбой 02:00 — тоже следующие сутки. Граница «≤ 00:00 next day» не режет
    // дефолтный вечерний ужин; никакого «ужина в 16:10».
    $w = MealPlan::windows(mskDate(), defaults(), pendingAll(), '02:00');
    expect((int) $w['dinner']['start']->format('H'))->toBeGreaterThanOrEqual(18)
        ->and($w['dinner']['start']->format('H:i'))->toBe('19:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('20:00');
});

it('keeps a monotonic order for a past-midnight bedtime (no inversion)', function () {
    // Полный день на pending при ночном отбое: строгий хронологический порядок,
    // ужин последним — инварианты фикса A не нарушены.
    $w = MealPlan::windows(mskDate(), defaults(), pendingAll(), '00:00');
    expect($w['lunch']['start']->greaterThan($w['breakfast']['start']))->toBeTrue()
        ->and($w['snack']['start']->greaterThan($w['lunch']['start']))->toBeTrue()
        ->and($w['dinner']['start']->greaterThan($w['snack']['start']))->toBeTrue();
});
