<?php

use App\Support\Nutrition\MealIntent;

/**
 * Инвариант промпта классификатора: инструкция про извлечение времени не должна
 * привязывать HH:MM к Москве. Классификатор per-profile — для немосковских
 * пользователей «в 10:00» это ИХ местное время, и модель не должна «конвертировать».
 */
it('does not hardcode Moscow in the meal-time extraction instruction', function () {
    $instruction = (new ReflectionClass(MealIntent::class))->getConstant('INSTRUCTION');

    expect($instruction)
        ->toBeString()
        ->not->toContain('Europe/Moscow')
        ->not->toContain('московск')
        ->not->toContain('Москв');
});
