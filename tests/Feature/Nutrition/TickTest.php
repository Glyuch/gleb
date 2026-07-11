<?php

use App\Actions\Nutrition\SendTopic;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionSentEvent;
use App\Models\NutritionTopic;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
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
        // Чек-ап просит СТРОГО JSON → возвращаем JSON с adjustments; саммари — обычный текст.
        'api.anthropic.com/*' => function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'JSON')
                ? Http::response(['content' => [['type' => 'text', 'text' => '{"message":"Разбор недели: держишь ритм.","adjustments":{"steps_target":9000}}']]])
                : Http::response(['content' => [['type' => 'text', 'text' => 'Ты сегодня большой молодец, итог дня отличный.']]]);
        },
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function outCount(): int
{
    return NutritionMessage::query()->where('direction', 'out')->count();
}

it('sends weight_request and greeting on Thursday morning and is idempotent', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 7, 35, 0, 'Europe/Moscow'));

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $kinds = NutritionMessage::query()->where('direction', 'out')->pluck('kind')->all();
    expect($kinds)->toContain('weight_request')
        ->and($kinds)->toContain('greeting');

    $after = outCount();

    // Повторный тик не дублирует события/сообщения.
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(outCount())->toBe($after);
});

it('sends the lunch reminder once at 11:05', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 11, 5, 0, 'Europe/Moscow'));

    // Завтрак уже залогирован утром — обеденное окно остаётся на дефолте (11:00–12:30).
    Planner::ensureDay(CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->first()->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-16 08:00:00',
    ]);

    $this->artisan('nutrition:tick')->assertExitCode(0);
    $this->artisan('nutrition:tick')->assertExitCode(0);

    $reminders = NutritionMessage::query()
        ->where('kind', 'reminder')
        ->where('content', 'like', '%Обед%')
        ->count();

    expect($reminders)->toBe(1);
    expect(NutritionSentEvent::query()->where('event_key', 'like', '%reminder:lunch')->count())->toBe(1);
});

it('sends the lunch followup at 13:05 when lunch is still pending', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 5, 0, 'Europe/Moscow'));

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $followup = NutritionMessage::query()
        ->where('kind', 'followup')
        ->where('content', 'like', '%Поели%')
        ->first();

    expect($followup)->not->toBeNull()
        ->and($followup->content)->toContain('обед');

    // Повторный тик не дублирует followup.
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('kind', 'followup')->count())->toBe(1);
});

it('creates a day summary at 22:35 from the chat model', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 22, 35, 0, 'Europe/Moscow'));

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $summary = NutritionMessage::query()->where('kind', 'summary')->first();
    expect($summary)->not->toBeNull()
        ->and($summary->content)->toContain('молодец');
});

it('runs the Sunday checkup at 20:05 and stores pending adjustments', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 19, 20, 5, 0, 'Europe/Moscow'));

    $this->artisan('nutrition:tick')->assertExitCode(0);

    expect(Settings::get('pending_adjustments'))->toBe(['steps_target' => 9000]);

    $checkup = NutritionMessage::query()->where('kind', 'checkup')->first();
    expect($checkup)->not->toBeNull()
        ->and($checkup->content)->toContain('Разбор недели');
});

it('sends every existing file of a multi-file topic with intro caption only on the first', function () {
    $dir = storage_path('app/nutrition/materials');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/__test_first.pdf', 'pdf-a');
    File::put($dir.'/__test_second.pdf', 'pdf-b');

    try {
        $topic = NutritionTopic::query()->create([
            'title' => 'Тестовая составная тема',
            'file_path' => '__test_first.pdf|__test_missing.pdf|__test_second.pdf',
            'intro' => 'Интро составной темы',
            'position' => 1,
        ]);

        app(SendTopic::class)->handle($topic);
    } finally {
        File::delete([$dir.'/__test_first.pdf', $dir.'/__test_second.pdf']);
    }

    // Два существующих файла ушли документами, отсутствующий пропущен.
    $docs = Http::recorded(fn ($request) => str_contains($request->url(), 'sendDocument'));
    expect($docs)->toHaveCount(2);

    // Intro — caption только у первого документа.
    expect(str_contains($docs[0][0]->body(), 'Интро составной темы'))->toBeTrue()
        ->and(str_contains($docs[1][0]->body(), 'Интро составной темы'))->toBeFalse();

    expect(NutritionMessage::query()->where('kind', 'topic')->count())->toBe(2)
        ->and($topic->fresh()->sent_at)->not->toBeNull();
});

it('falls back to intro text when none of the topic files exist on disk', function () {
    $topic = NutritionTopic::query()->create([
        'title' => 'Тема без файлов',
        'file_path' => '__test_absent_one.pdf|__test_absent_two.pdf',
        'intro' => 'Интро без файлов',
        'position' => 2,
    ]);

    app(SendTopic::class)->handle($topic);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendDocument'));

    $message = NutritionMessage::query()->where('kind', 'topic')->first();
    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('Интро без файлов');

    expect($topic->fresh()->sent_at)->not->toBeNull();
});
