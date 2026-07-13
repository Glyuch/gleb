<?php

use App\Support\Nutrition\Address;

it('prefixes «{Имя}, » for a named profile and nothing for a nameless one', function () {
    $named = nutritionProfile(['name' => 'Андрей']);
    $nameless = nutritionProfile(['telegram_user_id' => 333, 'name' => '', 'is_admin' => false]);

    expect(Address::prefix($named))->toBe('Андрей, ');
    expect(Address::prefix($nameless))->toBe('');
});

it('adds the name to AI text that does not start with it and lowercases the first cyrillic letter', function () {
    $profile = nutritionProfile(['name' => 'Андрей']);

    expect(Address::ensure($profile, 'Отлично поел! 🙌🏼'))->toBe('Андрей, отлично поел! 🙌🏼');
});

it('does not duplicate the name when the AI text already starts with it', function () {
    $profile = nutritionProfile(['name' => 'Андрей']);

    expect(Address::ensure($profile, 'Андрей, отлично поел!'))->toBe('Андрей, отлично поел!');
    // Регистр имени в тексте не важен для проверки дублирования.
    expect(Address::ensure($profile, 'андрей, ты молодец'))->toBe('андрей, ты молодец');
});

it('adds the name when it only matches a substring of a longer leading word', function () {
    $profile = nutritionProfile(['name' => 'Ян']);

    expect(Address::ensure($profile, 'Янтарная кислота помогает'))->toBe('Ян, янтарная кислота помогает');
    expect(Address::ensure($profile, 'Ян, молодец'))->toBe('Ян, молодец');
});

it('keeps abbreviations and single-letter initials in their original case', function () {
    $profile = nutritionProfile(['name' => 'Глеб']);

    expect(Address::ensure($profile, 'ЗОЖ — это система привычек'))->toBe('Глеб, ЗОЖ — это система привычек');
    expect(Address::ensure($profile, 'АД в норме'))->toBe('Глеб, АД в норме');
    expect(Address::ensure($profile, 'Отлично!'))->toBe('Глеб, отлично!');
});

it('leaves the text untouched for a nameless profile without failing', function () {
    $nameless = nutritionProfile(['telegram_user_id' => 333, 'name' => '', 'is_admin' => false]);

    expect(Address::ensure($nameless, 'Отлично поел!'))->toBe('Отлично поел!');
});

it('prefixes but does not lowercase when the text starts with an emoji or latin', function () {
    $profile = nutritionProfile(['name' => 'Андрей']);

    expect(Address::ensure($profile, '⏰ Обед 13:00–14:00'))->toBe('Андрей, ⏰ Обед 13:00–14:00');
    expect(Address::ensure($profile, 'OK, поел'))->toBe('Андрей, OK, поел');
});
