<?php

use App\Support\Nutrition\Timezone;

it('accepts a valid IANA identifier as-is', function () {
    expect(Timezone::parse('Europe/Berlin'))->toBe('Europe/Berlin')
        ->and(Timezone::parse('Asia/Yekaterinburg'))->toBe('Asia/Yekaterinburg')
        ->and(Timezone::parse('UTC'))->toBe('UTC');
});

it('normalizes offsets to +HH:MM', function () {
    expect(Timezone::parse('+2'))->toBe('+02:00')
        ->and(Timezone::parse('-5'))->toBe('-05:00')
        ->and(Timezone::parse('+5:30'))->toBe('+05:30')
        ->and(Timezone::parse('+0530'))->toBe('+05:30')
        ->and(Timezone::parse('+00'))->toBe('+00:00')
        ->and(Timezone::parse('+14'))->toBe('+14:00');
});

it('rejects out-of-range offsets', function () {
    expect(Timezone::parse('+15'))->toBeNull()
        ->and(Timezone::parse('+14:30'))->toBeNull()
        ->and(Timezone::parse('+99'))->toBeNull();
});

it('maps common cities (ru+en, case-insensitive, trimmed) to IANA', function () {
    expect(Timezone::parse('Берлин'))->toBe('Europe/Berlin')
        ->and(Timezone::parse('belgrade'))->toBe('Europe/Belgrade')
        ->and(Timezone::parse('  Ереван '))->toBe('Asia/Yerevan')
        ->and(Timezone::parse('МОСКВА'))->toBe('Europe/Moscow')
        ->and(Timezone::parse('New York'))->toBe('America/New_York');
});

it('returns null for garbage', function () {
    expect(Timezone::parse('лунапарк'))->toBeNull()
        ->and(Timezone::parse(''))->toBeNull()
        ->and(Timezone::parse('   '))->toBeNull()
        ->and(Timezone::parse('12345'))->toBeNull();
});
