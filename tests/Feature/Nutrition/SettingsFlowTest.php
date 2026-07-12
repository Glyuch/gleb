<?php

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Models\NutritionTopicSend;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;
use Database\Seeders\NutritionTopicSeeder;
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

function sentBodies(): array
{
    $bodies = [];
    foreach (Http::recorded() as [$request]) {
        $bodies[] = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
    }

    return $bodies;
}

function sentContains(string $needle): bool
{
    foreach (sentBodies() as $body) {
        if (str_contains($body, $needle)) {
            return true;
        }
    }

    return false;
}

it('onboards on /start without a started program and offers the start button', function () {
    // null — часть сценария: программа ещё не запущена.
    $this->profile->update(['program_started_on' => null]);

    app(HandleCommand::class)->handle(['message' => ['text' => '/start']], $this->profile);

    expect(sentContains('не запущена'))->toBeTrue()
        ->and(sentContains('program:start'))->toBeTrue();
});

it('starts the program on the program:start callback and asks for weight', function () {
    // null — часть сценария: кнопка «Начать программу» и должна установить дату.
    $this->profile->update(['program_started_on' => null]);
    $this->seed(NutritionTopicSeeder::class);
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbp',
        'data' => 'program:start',
    ]], $this->profile);

    expect($this->profile->fresh()->program_started_on->format('Y-m-d'))->toBe('2026-07-13');
    expect(NutritionTopicSend::query()->where('profile_id', $this->profile->id)->count())->toBe(12);
    expect(sentContains('вес'))->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery'));

    // Повторный запуск — «уже идёт», дата не меняется.
    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbp2',
        'data' => 'program:start',
    ]], $this->profile);

    expect($this->profile->fresh()->program_started_on->format('Y-m-d'))->toBe('2026-07-13');
    expect(sentContains('уже идёт'))->toBeTrue();
});

it('shows the three setting buttons on /settings', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/settings']], $this->profile);

    expect(sentContains('set:wake_time'))->toBeTrue()
        ->and(sentContains('set:sleep_time'))->toBeTrue()
        ->and(sentContains('set:steps_target'))->toBeTrue();
});

it('captures steps_target through the awaiting flow with validation', function () {
    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbs',
        'data' => 'set:steps_target',
    ]], $this->profile);

    expect($this->profile->fresh()->waiting('setting'))->toBe('steps_target');

    // Вне диапазона — подсказка, awaiting сохраняется.
    app(HandleNumbers::class)->handle(['message' => ['text' => '99']], $this->profile);
    expect($this->profile->fresh()->waiting('setting'))->toBe('steps_target')
        ->and((int) $this->profile->fresh()->setting('steps_target'))->toBe(7000);

    // Валидное значение — сохраняется, awaiting очищается.
    app(HandleNumbers::class)->handle(['message' => ['text' => '12000']], $this->profile);
    expect((int) $this->profile->fresh()->setting('steps_target'))->toBe(12000)
        ->and($this->profile->fresh()->waiting('setting'))->toBeNull();
});

it('captures sleep_time through the awaiting flow and recalculates windows', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbz',
        'data' => 'set:sleep_time',
    ]], $this->profile);
    expect($this->profile->fresh()->waiting('setting'))->toBe('sleep_time');

    app(HandleQuestion::class)->handle(['message' => ['text' => '22:30']], $this->profile);

    expect($this->profile->fresh()->setting('sleep_time'))->toBe('22:30')
        ->and($this->profile->fresh()->waiting('setting'))->toBeNull();

    $dinner = NutritionMeal::query()->where('type', 'dinner')->first();
    expect($dinner->window_end->format('H:i') <= '20:30')->toBeTrue();
});

it('treats awaiting_setting as stale when a scheduled request came later', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 21, 0, 0, 'Europe/Moscow'));

    // Нажал кнопку настройки, не ответил; вечером тик прислал metrics_request.
    $this->profile->setWaiting('setting', 'steps_target');
    NutritionMessage::query()->create(['profile_id' => $this->profile->id, 'direction' => 'out', 'kind' => 'metrics_request', 'content' => 'Шаги и вода?']);

    app(HandleNumbers::class)->handle(['message' => ['text' => '11500']], $this->profile);

    // Число ушло в метрику шагов, цель не тронута, устаревший awaiting сброшен.
    expect((int) NutritionMetric::query()->where('type', 'steps')->value('value'))->toBe(11500)
        ->and((int) $this->profile->fresh()->setting('steps_target'))->toBe(7000)
        ->and($this->profile->fresh()->waiting('setting'))->toBeNull();
});

it('accepts a setting value while the setting request is the last outgoing message', function () {
    $this->profile->setWaiting('setting', 'steps_target');
    NutritionMessage::query()->create(['profile_id' => $this->profile->id, 'direction' => 'out', 'kind' => 'setting_request', 'content' => 'Пришли число шагов']);

    app(HandleNumbers::class)->handle(['message' => ['text' => '12000']], $this->profile);

    expect((int) $this->profile->fresh()->setting('steps_target'))->toBe(12000)
        ->and($this->profile->fresh()->waiting('setting'))->toBeNull()
        ->and(NutritionMetric::query()->where('type', 'steps')->count())->toBe(0);
});

it('resets awaiting_setting when any command arrives', function () {
    $this->profile->setWaiting('setting', 'wake_time');

    app(HandleCommand::class)->handle(['message' => ['text' => '/today']], $this->profile);

    expect($this->profile->fresh()->waiting('setting'))->toBeNull();
});
