<?php

use App\Support\Game\Scenario;
use Tests\TestCase;

// Boot the framework (Unit tests don't by default) so config() works. No DB needed.
uses(TestCase::class);

beforeEach(function () {
    config(['game.contribution' => 30000]);
});

function twoQuarterScenario(): Scenario
{
    // bank steady +10%/q; stock +50% then -20%.
    return new Scenario([
        'choices' => [
            ['k' => 'bank', 't' => 'Вклад'],
            ['k' => 'stock', 't' => 'Акции'],
        ],
        'years' => [
            ['ret' => ['bank' => 0.10, 'stock' => 0.50], 'rate' => 10, 'infl' => 2,
                'ev' => ['title' => 'Q1', 'type' => 'up', 'text' => 'a'], 'ctx' => 'c1'],
            ['ret' => ['bank' => 0.10, 'stock' => -0.20], 'rate' => 9, 'infl' => 1.5,
                'ev' => ['title' => 'Q2', 'type' => 'down', 'text' => 'b'], 'ctx' => 'c2'],
        ],
    ]);
}

it('exposes instruments, labels, counts and series', function () {
    $s = twoQuarterScenario();
    expect($s->instruments())->toBe(['bank', 'stock']);
    expect($s->labels())->toBe(['bank' => 'Вклад', 'stock' => 'Акции']);
    expect($s->quarterCount())->toBe(2);
    expect($s->rate())->toBe([10.0, 9.0]);
    expect($s->inflation())->toBe([2.0, 1.5]);
    expect($s->returns())->toBe(['bank' => [0.10, 0.10], 'stock' => [0.50, -0.20]]);
});

it('computes forward factors with q+1 timing', function () {
    $s = twoQuarterScenario();
    // q0 grows on q1 only; q1 is the last quarter (empty product = 1).
    expect(round($s->forwardFactor('bank', 0), 4))->toBe(1.10);
    expect(round($s->forwardFactor('stock', 0), 4))->toBe(0.80);
    expect($s->forwardFactor('bank', 1))->toBe(1.0);
});

it('computes deterministic benchmarks', function () {
    // c=30000. q0: bankFactor 1.10, best 1.10 (bank beats stock 0.80). q1: both 1.0.
    // bank = 30000*1.10 + 30000*1.0 = 63000; max = same = 63000.
    expect(twoQuarterScenario()->benchmarks())->toBe(['bank' => 63000, 'max' => 63000]);
});

it('computes per-quarter optimum (forward) and best-by-return, ties to bank', function () {
    $s = twoQuarterScenario();
    expect($s->optimumPerQuarter())->toBe([0 => 'bank', 1 => 'bank']); // q1 tie -> bank
    expect($s->bestByQuarterReturn())->toBe([0 => 'stock', 1 => 'bank']); // q0 stock 0.5 > 0.1
});

it('reads per-quarter narrative with fallback', function () {
    $n = twoQuarterScenario()->narrative();
    expect($n[0])->toBe(['title' => 'Q1', 'type' => 'up', 'text' => 'a']);
});
