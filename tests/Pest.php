<?php

use App\Models\NutritionProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Сеет профиль нутрициолога для фикстур. По умолчанию — активный admin-профиль
 * (владелец инстанса) с заданным main_chat_id, что покрывает и маршрутизацию
 * входящих (резолвинг по telegram_user_id), и тик (цикл по active-профилям с
 * отправкой в main_chat_id). Для сценариев «профиль без чата» переопредели
 * main_chat_id => null.
 *
 * @param  array<string, mixed>  $attrs
 */
function nutritionProfile(array $attrs = []): NutritionProfile
{
    return NutritionProfile::query()->create(array_merge([
        'telegram_user_id' => 49465703,
        'name' => 'Глеб',
        'main_chat_id' => 123,
        'status' => 'active',
        // Программа по умолчанию запущена: тик-гейт по program_started_on пройден.
        // Там, где null — часть сценария, переопредели 'program_started_on' => null.
        'program_started_on' => now('Europe/Moscow')->toDateString(),
        'is_admin' => true,
    ], $attrs));
}
