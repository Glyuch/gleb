<?php

use App\Actions\Nutrition\HandleCommand;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Support\Nutrition\NutritionStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

it('rejects the stats page without a valid signature', function () {
    $profile = nutritionProfile();

    $this->get('/nutrition/s/'.$profile->id)->assertForbidden();
});

it('serves the stats page with a valid signature', function () {
    $profile = nutritionProfile(['name' => 'Настя']);

    $this->get(URL::signedRoute('nutrition.stats', ['profile' => $profile->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('nutrition/stats')
            ->where('profile.name', 'Настя'));
});

it('exposes only the requested profile data', function () {
    $now = CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow');
    $this->travelTo($now);

    $a = nutritionProfile(['telegram_user_id' => 111, 'name' => 'A']);
    $b = nutritionProfile(['telegram_user_id' => 222, 'name' => 'B', 'is_admin' => false]);

    $today = $now->format('Y-m-d');
    NutritionMetric::create(['profile_id' => $a->id, 'date' => $today, 'type' => 'weight', 'value' => 80]);
    NutritionMetric::create(['profile_id' => $b->id, 'date' => $today, 'type' => 'weight', 'value' => 90]);
    NutritionMeal::create(['profile_id' => $b->id, 'date' => $today, 'type' => 'lunch', 'status' => 'eaten', 'eaten_at' => $now, 'score' => 9]);

    $data = NutritionStats::for($a);

    expect($data['weights'])->toHaveCount(1)
        ->and($data['weights'][0]['value'])->toBe(80.0)
        ->and($data['scores'])->toHaveCount(0)
        ->and($data['recentMeals'])->toHaveCount(0)
        ->and($data['profile']['name'])->toBe('A');
});

it('tolerates null score and rating in recent meals', function () {
    $now = CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow');
    $this->travelTo($now);

    $profile = nutritionProfile();
    NutritionMeal::create([
        'profile_id' => $profile->id,
        'date' => $now->format('Y-m-d'),
        'type' => 'breakfast',
        'status' => 'eaten',
        'eaten_at' => $now->setTime(8, 0),
        'score' => null,
        'rating' => null,
    ]);

    $data = NutritionStats::for($profile);

    expect($data['recentMeals'])->toHaveCount(1)
        ->and($data['recentMeals'][0]['score'])->toBeNull()
        ->and($data['recentMeals'][0]['forbidden'])->toBe([])
        ->and($data['recentMeals'][0]['window_ok'])->toBeNull();
});

it('includes a signed stats link in the /stats bot reply', function () {
    config([
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => 'test-token',
    ]);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $profile = nutritionProfile();

    app(HandleCommand::class)->handle(
        ['message' => ['text' => '/stats', 'chat' => ['id' => 123]]],
        $profile
    );

    $out = NutritionMessage::query()->where('direction', 'out')->orderByDesc('id')->value('content');

    expect($out)->toContain('/nutrition/s/'.$profile->id);
});
