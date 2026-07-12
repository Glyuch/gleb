<?php

use App\Actions\Nutrition\SendTopic;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionProfile;
use App\Models\NutritionSentEvent;
use App\Models\NutritionTopic;
use App\Support\Nutrition\Planner;
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

    $this->profile = nutritionProfile();

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

/**
 * Готовит день так, чтобы обед был единственным «горячим» приёмом с окном 13:00–14:00:
 * завтрак отмечен eaten (иначе он уйдёт в missed и пересчитает окна), обед — pending.
 */
function prepareLunchWindow(NutritionProfile $profile, string $start = '2026-07-16 13:00:00', string $end = '2026-07-16 14:00:00'): NutritionMeal
{
    Planner::ensureDay($profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->first()->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-16 08:00:00',
    ]);
    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    $lunch->update(['window_start' => $start, 'window_end' => $end]);

    return $lunch->fresh();
}

it('sends the lunch pre-reminder 30 minutes before the window and only once', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 12, 30, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $pre = NutritionMessage::query()
        ->where('content', 'like', '%Через полчаса%')
        ->where('content', 'like', '%Обед%')
        ->get();
    expect($pre)->toHaveCount(1);
    expect($pre->first()->content)->toContain('13:00–14:00');
    expect(NutritionSentEvent::query()->where('event_key', '2026-07-16:pre:lunch:13:00')->count())->toBe(1);

    // Повторный тик в 12:31 (то же пред-окно) не дублирует.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 12, 31, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%Через полчаса%')->count())->toBe(1);
});

it('sends the full lunch reminder with composition and buttons at the window start', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $reminder = NutritionMessage::query()
        ->where('kind', 'reminder')
        ->where('content', 'like', '%Обед 13:00–14:00%')
        ->first();
    expect($reminder)->not->toBeNull()
        ->and($reminder->content)->toContain('Правило тарелки'); // состав обеда
    expect(NutritionSentEvent::query()->where('event_key', '2026-07-16:meal:lunch:13:00')->count())->toBe(1);

    // Кнопки «Поел раньше» приложены к старт-напоминанию.
    $withButtons = Http::recorded(fn ($request) => str_contains($request->url(), 'sendMessage')
        && str_contains($request->body(), 'atepast'));
    expect($withButtons)->not->toBeEmpty();
});

it('sends a lunch nudge every 30 minutes while pending but not once eaten', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 30, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $nudge = NutritionMessage::query()->where('kind', 'followup')->where('content', 'like', '%Поели обед%')->get();
    expect($nudge)->toHaveCount(1);
    expect(NutritionSentEvent::query()->where('event_key', '2026-07-16:meal:lunch:13:30')->count())->toBe(1);

    // Тот же 30-мин слот — без дублей.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 31, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%Поели обед%')->count())->toBe(1);

    // Приём отмечен eaten → следующий слот (14:00) не пингует.
    NutritionMeal::query()->where('type', 'lunch')->first()->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-16 13:45:00',
    ]);
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 14, 0, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%Поели обед%')->count())->toBe(1);
});

it('regenerates the reminder when the meal window is moved to a later time', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));
    // Окно обеда уже пересчитано на 13:00–14:00.
    prepareLunchWindow($this->profile);
    // По СТАРОМУ окну (11:30) напоминание уже уходило — ключ израсходован.
    NutritionSentEvent::query()->create([
        'event_key' => '2026-07-16:meal:lunch:11:30',
        'sent_at' => '2026-07-16 11:30:00',
    ]);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Сдвиг окна дал новый ключ 13:00 → напоминание УХОДИТ повторно.
    expect(NutritionSentEvent::query()->where('event_key', '2026-07-16:meal:lunch:13:00')->count())->toBe(1);
    expect(
        NutritionMessage::query()->where('content', 'like', '%Обед 13:00–14:00%')->first()
    )->not->toBeNull();
});

it('does not duplicate a slot reminder within the same 30-minute bucket', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);
    $this->artisan('nutrition:tick')->assertExitCode(0);
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 20, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);

    expect(NutritionMessage::query()->where('kind', 'reminder')->where('content', 'like', '%Обед 13:00%')->count())->toBe(1);
    expect(NutritionSentEvent::query()->where('event_key', 'like', '2026-07-16:meal:lunch:%')->count())->toBe(1);
});

it('does not burst-backfill missed slots when the worker was down (first tick at 14:20)', function () {
    // Симуляция простоя воркера: до 14:20 тик не запускался, слот-события пусты.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 14, 20, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Уходит РОВНО одно напоминание по обеду — наджа текущего 30-мин ведра (14:00),
    // а не пачка за пропущенные слоты 13:00/13:30.
    expect(NutritionMessage::query()->where('content', 'like', '%Поели обед%')->count())->toBe(1);
    expect(NutritionSentEvent::query()->where('event_key', 'like', '2026-07-16:meal:lunch:%')->count())->toBe(1);
    expect(NutritionSentEvent::query()->where('event_key', '2026-07-16:meal:lunch:14:00')->count())->toBe(1);

    // Пропущенные слоты НЕ материализованы.
    expect(NutritionSentEvent::query()->where('event_key', '2026-07-16:meal:lunch:13:00')->exists())->toBeFalse()
        ->and(NutritionSentEvent::query()->where('event_key', '2026-07-16:meal:lunch:13:30')->exists())->toBeFalse();
});

it('in maintenance sends only the start reminder, no pre and no nudges', function () {
    $this->profile->update(['phase' => 'maintenance']);

    // Пре-напоминание в 12:30 — не уходит.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 12, 30, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%Через полчаса%')->count())->toBe(0);

    // Старт в 13:00 — уходит.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('kind', 'reminder')->where('content', 'like', '%Обед 13:00–14:00%')->count())->toBe(1);

    // Надж в 13:30 — не уходит (мягкий режим).
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 30, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%Поели обед%')->count())->toBe(0);
});

it('does not persist slot reminders in dry-run mode', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $beforeEvents = NutritionSentEvent::query()->count();
    $beforeMessages = NutritionMessage::query()->count();

    $this->artisan('nutrition:tick', ['--at' => '2026-07-16 13:00'])->assertExitCode(0);

    expect(NutritionSentEvent::query()->count())->toBe($beforeEvents)
        ->and(NutritionMessage::query()->count())->toBe($beforeMessages);
    Http::assertNothingSent();
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

    expect($this->profile->fresh()->waiting('pending_adjustments'))->toBe(['steps_target' => 9000]);

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

        app(SendTopic::class)->handle($this->profile, $topic);
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

    app(SendTopic::class)->handle($this->profile, $topic);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendDocument'));

    $message = NutritionMessage::query()->where('kind', 'topic')->first();
    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('Интро без файлов');

    expect($topic->fresh()->sent_at)->not->toBeNull();
});
it('does nothing on a live tick when chat_id is not configured', function () {
    config(['nutrition.chat_id' => null]);
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 7, 35, 0, 'Europe/Moscow'));

    $this->artisan('nutrition:tick')->assertExitCode(0);

    expect(NutritionMeal::query()->count())->toBe(0)
        ->and(NutritionSentEvent::query()->count())->toBe(0)
        ->and(NutritionMessage::query()->count())->toBe(0);
    Http::assertNothingSent();
});
