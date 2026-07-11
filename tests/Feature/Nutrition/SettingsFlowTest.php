<?php

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Models\NutritionTopic;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\Settings;
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
    app(HandleCommand::class)->handle(['message' => ['text' => '/start']]);

    expect(sentContains('не запущена'))->toBeTrue()
        ->and(sentContains('program:start'))->toBeTrue();
});

it('starts the program on the program:start callback and asks for weight', function () {
    $this->seed(NutritionTopicSeeder::class);
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbp',
        'data' => 'program:start',
    ]]);

    expect(Settings::get('program_started_on'))->toBe('2026-07-13');
    expect(NutritionTopic::query()->whereNotNull('scheduled_on')->count())->toBe(12);
    expect(sentContains('вес'))->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery'));

    // Повторный запуск — «уже идёт», дата не меняется.
    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbp2',
        'data' => 'program:start',
    ]]);

    expect(Settings::get('program_started_on'))->toBe('2026-07-13');
    expect(sentContains('уже идёт'))->toBeTrue();
});

it('shows the three setting buttons on /settings', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/settings']]);

    expect(sentContains('set:wake_time'))->toBeTrue()
        ->and(sentContains('set:sleep_time'))->toBeTrue()
        ->and(sentContains('set:steps_target'))->toBeTrue();
});

it('captures steps_target through the awaiting flow with validation', function () {
    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbs',
        'data' => 'set:steps_target',
    ]]);

    expect(Settings::get('awaiting_setting'))->toBe('steps_target');

    // Вне диапазона — подсказка, awaiting сохраняется.
    app(HandleNumbers::class)->handle(['message' => ['text' => '99']]);
    expect(Settings::get('awaiting_setting'))->toBe('steps_target')
        ->and((int) Settings::get('steps_target'))->toBe(7000);

    // Валидное значение — сохраняется, awaiting очищается.
    app(HandleNumbers::class)->handle(['message' => ['text' => '12000']]);
    expect((int) Settings::get('steps_target'))->toBe(12000)
        ->and(Settings::get('awaiting_setting'))->toBeNull();
});

it('captures sleep_time through the awaiting flow and recalculates windows', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 9, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay(CarbonImmutable::now('Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbz',
        'data' => 'set:sleep_time',
    ]]);
    expect(Settings::get('awaiting_setting'))->toBe('sleep_time');

    app(HandleQuestion::class)->handle(['message' => ['text' => '22:30']]);

    expect(Settings::get('sleep_time'))->toBe('22:30')
        ->and(Settings::get('awaiting_setting'))->toBeNull();

    $dinner = NutritionMeal::query()->where('type', 'dinner')->first();
    expect($dinner->window_end->format('H:i') <= '20:30')->toBeTrue();
});

it('treats awaiting_setting as stale when a scheduled request came later', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 21, 0, 0, 'Europe/Moscow'));

    // Нажал кнопку настройки, не ответил; вечером тик прислал metrics_request.
    Settings::set('awaiting_setting', 'steps_target');
    NutritionMessage::query()->create(['direction' => 'out', 'kind' => 'metrics_request', 'content' => 'Шаги и вода?']);

    app(HandleNumbers::class)->handle(['message' => ['text' => '11500']]);

    // Число ушло в метрику шагов, цель не тронута, устаревший awaiting сброшен.
    expect((int) NutritionMetric::query()->where('type', 'steps')->value('value'))->toBe(11500)
        ->and((int) Settings::get('steps_target'))->toBe(7000)
        ->and(Settings::get('awaiting_setting'))->toBeNull();
});

it('accepts a setting value while the setting request is the last outgoing message', function () {
    Settings::set('awaiting_setting', 'steps_target');
    NutritionMessage::query()->create(['direction' => 'out', 'kind' => 'setting_request', 'content' => 'Пришли число шагов']);

    app(HandleNumbers::class)->handle(['message' => ['text' => '12000']]);

    expect((int) Settings::get('steps_target'))->toBe(12000)
        ->and(Settings::get('awaiting_setting'))->toBeNull()
        ->and(NutritionMetric::query()->where('type', 'steps')->count())->toBe(0);
});

it('resets awaiting_setting when any command arrives', function () {
    Settings::set('awaiting_setting', 'wake_time');

    app(HandleCommand::class)->handle(['message' => ['text' => '/today']]);

    expect(Settings::get('awaiting_setting'))->toBeNull();
});
