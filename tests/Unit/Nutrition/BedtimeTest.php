<?php

use App\Support\Nutrition\Bedtime;

it('treats «12» and «в 12» as midnight for bedtime', function () {
    expect(Bedtime::fromText('12'))->toBe(['status' => 'ok', 'value' => '00:00'])
        ->and(Bedtime::fromText('в 12'))->toBe(['status' => 'ok', 'value' => '00:00'])
        ->and(Bedtime::fromText('в 12 ночи'))->toBe(['status' => 'ok', 'value' => '00:00'])
        ->and(Bedtime::fromText('12:00'))->toBe(['status' => 'ok', 'value' => '00:00']);
});

it('keeps valid night/evening bedtimes', function () {
    expect(Bedtime::fromText('23:00'))->toBe(['status' => 'ok', 'value' => '23:00'])
        ->and(Bedtime::fromText('22:30'))->toBe(['status' => 'ok', 'value' => '22:30'])
        ->and(Bedtime::fromText('00:30'))->toBe(['status' => 'ok', 'value' => '00:30']);
});

it('asks again for an absurd daytime bedtime', function () {
    expect(Bedtime::fromText('14:00'))->toBe(['status' => 'reask'])
        ->and(Bedtime::fromText('09:00'))->toBe(['status' => 'reask']);
});

it('reports invalid input without any time', function () {
    expect(Bedtime::fromText('спокойной ночи'))->toBe(['status' => 'invalid'])
        ->and(Bedtime::fromText(''))->toBe(['status' => 'invalid']);
});

it('parses «24:00» and «полночь» as midnight', function () {
    expect(Bedtime::fromText('24:00'))->toBe(['status' => 'ok', 'value' => '00:00'])
        ->and(Bedtime::fromText('полночь'))->toBe(['status' => 'ok', 'value' => '00:00'])
        ->and(Bedtime::fromText('в полночь'))->toBe(['status' => 'ok', 'value' => '00:00'])
        ->and(Bedtime::fromText('12 ночи'))->toBe(['status' => 'ok', 'value' => '00:00']);
});

it('treats an explicit noon as an absurd bedtime and reasks', function () {
    expect(Bedtime::fromText('полдень'))->toBe(['status' => 'reask'])
        ->and(Bedtime::fromText('в полдень'))->toBe(['status' => 'reask'])
        ->and(Bedtime::fromText('12 дня'))->toBe(['status' => 'reask'])
        ->and(Bedtime::fromText('в 12 дня'))->toBe(['status' => 'reask']);
});
