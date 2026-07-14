<?php

use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMeal;
use App\Support\Nutrition\MealIntent;
use App\Support\Nutrition\MealLogger;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => 'test-token',
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.fast' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);

    $this->profile = nutritionProfile();

    Http::preventStrayRequests();
});

it('routes the meal-intent classifier to the fast (Haiku) model', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"intent":"question","reports":[],"reply":"ок"}']]]),
    ]);

    MealIntent::classify($this->profile, 'а можно банан на ужин?', $this->profile->now());

    // Классификатор гоняется на каждое сообщение — должен идти дешёвым Haiku (max_tokens 500).
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && $r['model'] === 'claude-haiku-4-5'
        && $r['max_tokens'] === 500);
});

it('routes the free-form question answer to the chat (Sonnet) model', function () {
    // Anthropic отдаёт не-JSON → classify вернёт null → фолбэк-ответ на вопрос.
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'обычный текстовый ответ, не json']]]),
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'Можно банан на ужин?']], $this->profile);

    // Классификатор — на Haiku (500), а живой ответ на вопрос — на Sonnet (800).
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && $r['model'] === 'claude-haiku-4-5'
        && $r['max_tokens'] === 500);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && $r['model'] === 'claude-sonnet-5'
        && $r['max_tokens'] === 800);
});

it('routes the meal re-evaluation to the fast (Haiku) model', function () {
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    $meal = NutritionMeal::query()->where('type', 'lunch')->first();

    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"feedback":"чистый белок","score":9,"composition_ok":true,"forbidden":[],"comment":"по тексту"}']]]),
    ]);

    MealLogger::reevaluate($this->profile, $meal, 'это куриная грудка на гриле');

    // Переоценка приёма — механика, идёт дешёвым Haiku (max_tokens 400).
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && $r['model'] === 'claude-haiku-4-5'
        && $r['max_tokens'] === 400);
});
