<?php

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandlePhoto;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\Settings;
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

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
        'api.telegram.org/file/*' => Http::response('BINARYIMAGE'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        // Скрин шагомера содержит промпт «трекер активности» → отдаём число; иначе — реакцию на еду.
        'api.anthropic.com/*' => function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'трекер активности')
                ? Http::response(['content' => [['type' => 'text', 'text' => '11200']]])
                : Http::response(['content' => [['type' => 'text', 'text' => 'Идеально! 🙌🏼']]]);
        },
    ]);
});

function lastOutText(): ?string
{
    return NutritionMessage::query()->where('direction', 'out')->orderByDesc('id')->value('content');
}

it('records a weight metric from /weight', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));

    app(HandleCommand::class)->handle(['message' => ['text' => '/weight 82.3']]);

    $metric = NutritionMetric::query()->where('type', 'weight')->first();
    expect($metric)->not->toBeNull()
        ->and((float) $metric->value)->toBe(82.3);
    expect(lastOutText())->toContain('82.3');
});

it('hints the format for an invalid /weight', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/weight abc']]);

    expect(NutritionMetric::query()->where('type', 'weight')->count())->toBe(0);
    expect(lastOutText())->toContain('/weight');
});

it('rejects an out-of-range /weight and writes no metric', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/weight 823']]);

    expect(NutritionMetric::query()->where('type', 'weight')->count())->toBe(0);
    expect(lastOutText())->toContain('/weight');
});

it('rejects out-of-range /steps and writes no metric', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/steps 999999']]);

    expect(NutritionMetric::query()->where('type', 'steps')->count())->toBe(0);
    expect(lastOutText())->toContain('/steps');
});

it('lists the day with the lunch label on /today', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));

    app(HandleCommand::class)->handle(['message' => ['text' => '/today']]);

    expect(lastOutText())->toContain('Обед');
});

it('marks the current meal eaten from a food photo and stores ai feedback', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]]);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->ai_feedback)->toBe('Идеально! 🙌🏼')
        ->and($lunch->photo_file_id)->toBe('big');
    expect(lastOutText())->toContain('Идеально');
});

it('replies there are no snacks when a photo arrives with no current meal', function () {
    // Late night: all windows are closed, currentMeal() returns null.
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 22, 30, 0, 'Europe/Moscow'));

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]]);

    expect(NutritionMeal::query()->where('status', 'eaten')->count())->toBe(0);
    expect(lastOutText())->toContain('Перекусов');
});

it('reads steps from a pedometer screenshot after a metrics request', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 22, 30, 0, 'Europe/Moscow'));

    NutritionMessage::query()->create(['direction' => 'out', 'kind' => 'metrics_request', 'content' => 'Пришли шаги']);

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]]);

    $steps = NutritionMetric::query()->where('type', 'steps')->first();
    expect($steps)->not->toBeNull()
        ->and((int) $steps->value)->toBe(11200);
    expect(NutritionMeal::query()->where('status', 'eaten')->count())->toBe(0);
});

it('records weight from a bare number after a weight request', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 8, 0, 0, 'Europe/Moscow'));

    NutritionMessage::query()->create(['direction' => 'out', 'kind' => 'weight_request', 'content' => 'Взвесься']);

    app(HandleNumbers::class)->handle(['message' => ['text' => '82.3']]);

    $metric = NutritionMetric::query()->where('type', 'weight')->first();
    expect($metric)->not->toBeNull()
        ->and((float) $metric->value)->toBe(82.3);
});

it('records steps and water from two numbers after a metrics request', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 21, 0, 0, 'Europe/Moscow'));

    NutritionMessage::query()->create(['direction' => 'out', 'kind' => 'metrics_request', 'content' => 'Шаги и вода?']);

    app(HandleNumbers::class)->handle(['message' => ['text' => '11200 2.5']]);

    expect((int) NutritionMetric::query()->where('type', 'steps')->value('value'))->toBe(11200)
        ->and((float) NutritionMetric::query()->where('type', 'water')->value('value'))->toBe(2.5);
});

it('routes a non-metric number reply to the question handler', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));

    app(HandleNumbers::class)->handle(['message' => ['text' => '3']]);

    // No pending weight/metrics request -> falls through to a Claude chat answer.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
});

it('marks a meal eaten from an ate callback', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cb1',
        'data' => 'ate:lunch',
    ]]);

    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('eaten');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery'));
});

it('skips a meal from a skip callback', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cb2',
        'data' => 'skip:lunch',
    ]]);

    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('skipped');
});

it('does not overwrite an already eaten meal on a repeated ate callback', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay(CarbonImmutable::now('Europe/Moscow'));

    NutritionMeal::query()->where('type', 'lunch')->first()->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-13 11:10:00',
        'photo_file_id' => 'orig-photo',
        'ai_feedback' => 'Отличный обед!',
    ]);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cb5',
        'data' => 'ate:lunch',
    ]]);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->eaten_at->format('H:i'))->toBe('11:10')
        ->and($lunch->photo_file_id)->toBe('orig-photo')
        ->and($lunch->ai_feedback)->toBe('Отличный обед!');
    expect(lastOutText())->toContain('уже отмечен');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery'));
});

it('does not skip an already eaten meal on a stale skip callback', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay(CarbonImmutable::now('Europe/Moscow'));

    NutritionMeal::query()->where('type', 'lunch')->first()->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-13 11:10:00',
    ]);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cb6',
        'data' => 'skip:lunch',
    ]]);

    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('eaten');
    expect(lastOutText())->toContain('уже отмечен');
});

it('applies pending adjustments on adj:yes and clears them', function () {
    Settings::set('pending_adjustments', ['steps_target' => 9000]);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cb3',
        'data' => 'adj:yes',
    ]]);

    expect((int) Settings::get('steps_target'))->toBe(9000)
        ->and(Settings::get('pending_adjustments'))->toBeNull();
});

it('discards pending adjustments on adj:no', function () {
    Settings::set('pending_adjustments', ['steps_target' => 9000]);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cb4',
        'data' => 'adj:no',
    ]]);

    expect((int) Settings::get('steps_target'))->toBe(7000)
        ->and(Settings::get('pending_adjustments'))->toBeNull();
    expect(lastOutText())->toContain('оставляем как есть');
});

it('answers a free-form question via the chat model', function () {
    app(HandleQuestion::class)->handle(['message' => ['text' => 'Можно ли банан на ужин?']]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com')
        && $request['model'] === 'claude-sonnet-5'
        && $request['max_tokens'] === 800);
    expect(lastOutText())->toContain('Идеально');
});

it('runs a checkup on /checkup', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/checkup']]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    expect(NutritionMessage::query()->where('kind', 'checkup')->exists())->toBeTrue();
});
