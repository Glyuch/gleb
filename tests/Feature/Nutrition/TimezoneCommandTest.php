<?php

use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMeal;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => 'test-token',
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);

    $this->profile = nutritionProfile();

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Ответ']]]),
    ]);
});

it('shows current timezone and local time and awaits input on /timezone', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'UTC')); // Москва 15:00

    app(HandleCommand::class)->handle(['message' => ['text' => '/timezone']], $this->profile);

    expect($this->profile->fresh()->waiting('timezone'))->toBeTrue();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Europe/Moscow')
        && str_contains($r['text'], '15:00'));
});

it('sets the timezone from the awaiting flow and confirms with new windows', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 6, 0, 0, 'UTC')); // Москва 09:00
    Planner::ensureDay($this->profile, $this->profile->now());

    app(HandleCommand::class)->handle(['message' => ['text' => '/timezone']], $this->profile);
    expect($this->profile->fresh()->waiting('timezone'))->toBeTrue();

    // «+5» приходит следующим сообщением и перехватывается как пояс.
    app(HandleQuestion::class)->handle(['message' => ['text' => '+5']], $this->profile);

    $fresh = $this->profile->fresh();
    expect($fresh->timezone)->toBe('+05:00')
        ->and($fresh->waiting('timezone'))->toBeNull();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'поставил пояс +05:00')
        && str_contains($r['text'], 'Окна на сегодня'));
});

it('accepts an inline argument: /timezone Берлин', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow'));

    app(HandleCommand::class)->handle(['message' => ['text' => '/timezone Берлин']], $this->profile);

    expect($this->profile->fresh()->timezone)->toBe('Europe/Berlin')
        ->and($this->profile->fresh()->waiting('timezone'))->toBeNull();
});

it('keeps awaiting and hints on unrecognized input', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/timezone']], $this->profile);
    app(HandleQuestion::class)->handle(['message' => ['text' => 'абырвалг']], $this->profile);

    expect($this->profile->fresh()->waiting('timezone'))->toBeTrue()
        ->and($this->profile->fresh()->timezone)->toBe('Europe/Moscow');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Не распознал пояс'));
});

it('preserves eaten meals and their eaten_at on a midday timezone move', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 6, 0, 0, 'UTC')); // Москва 09:00
    $now = $this->profile->now();
    Planner::ensureDay($this->profile, $now);

    // Отмечаем завтрак съеденным в 08:00 (по Москве).
    $breakfast = NutritionMeal::query()
        ->where('profile_id', $this->profile->id)->where('type', 'breakfast')->first();
    Planner::markEaten($this->profile, $breakfast, $now->setTime(8, 0), null, 'ok');

    $eatenAtBefore = $breakfast->fresh()->eaten_at->format('Y-m-d H:i:s');

    // Переезд серединой дня в пояс +02:00.
    app(HandleCommand::class)->handle(['message' => ['text' => '/timezone +02:00']], $this->profile);

    $breakfastAfter = $breakfast->fresh();
    expect($this->profile->fresh()->timezone)->toBe('+02:00')
        // Съеденный приём и его eaten_at не тронуты.
        ->and($breakfastAfter->status)->toBe('eaten')
        ->and($breakfastAfter->eaten_at->format('Y-m-d H:i:s'))->toBe($eatenAtBefore);

    // Не съеденные приёмы остаются pending и имеют окна (пересчитаны под новый пояс).
    $lunch = NutritionMeal::query()
        ->where('profile_id', $this->profile->id)->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('pending')
        ->and($lunch->window_start)->not->toBeNull();
});
