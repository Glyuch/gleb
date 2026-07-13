<?php

use App\Actions\Nutrition\SendTopic;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionProfile;
use App\Models\NutritionSentEvent;
use App\Models\NutritionTopic;
use App\Models\NutritionTopicSend;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => 'test-token',
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);

    // Активный admin-профиль владельца с main_chat_id=123.
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

/** Ключ sent_events с профиль-префиксом. */
function pkey(NutritionProfile $profile, string $bare): string
{
    return 'p'.$profile->id.':'.$bare;
}

it('evaluates tick windows in each profile local timezone (isolation)', function () {
    // Один и тот же момент: 07:30 по Москве = 06:30 в поясе +02:00.
    $this->travelTo(CarbonImmutable::create(2026, 7, 15, 7, 30, 0, 'Europe/Moscow'));

    $moscow = $this->profile;                                    // admin, Europe/Moscow, чат 123
    $plus2 = nutritionProfile([
        'telegram_user_id' => 222,
        'is_admin' => false,
        'main_chat_id' => 456,
        'timezone' => '+02:00',
    ]);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $d = '2026-07-15';

    // Москва: местное 07:30 = старт окна завтрака → напоминание ушло.
    expect(NutritionSentEvent::where('event_key', pkey($moscow, "{$d}:meal:breakfast:07:30"))->exists())->toBeTrue()
        // +02:00: местное 06:30 — окно завтрака (07:30 по его времени) ещё не наступило.
        ->and(NutritionSentEvent::where('event_key', pkey($plus2, "{$d}:meal:breakfast:07:30"))->exists())->toBeFalse();

    // Приветствие (в wake+30 = 07:30): у Москвы есть, у +02:00 (06:30) — нет.
    expect(NutritionSentEvent::where('event_key', pkey($moscow, "{$d}:greeting"))->exists())->toBeTrue()
        ->and(NutritionSentEvent::where('event_key', pkey($plus2, "{$d}:greeting"))->exists())->toBeFalse();
});

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
    NutritionMeal::query()->where('profile_id', $profile->id)->where('type', 'breakfast')->first()->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-16 08:00:00',
    ]);
    $lunch = NutritionMeal::query()->where('profile_id', $profile->id)->where('type', 'lunch')->first();
    $lunch->update(['window_start' => $start, 'window_end' => $end]);

    return $lunch->fresh();
}

it('sends the lunch pre-reminder 30 minutes before the window and only once', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 12, 30, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $pre = NutritionMessage::query()
        ->where('content', 'like', '%через полчаса%')
        ->where('content', 'like', '%Обед%')
        ->get();
    expect($pre)->toHaveCount(1);
    expect($pre->first()->content)->toContain('13:00–14:00');
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:pre:lunch:13:00'))->count())->toBe(1);

    // Повторный тик в 12:31 (то же пред-окно) не дублирует.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 12, 31, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%через полчаса%')->count())->toBe(1);
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
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:13:00'))->count())->toBe(1);

    // Кнопки «Поел раньше» приложены к старт-напоминанию.
    $withButtons = Http::recorded(fn ($request) => str_contains($request->url(), 'sendMessage')
        && str_contains($request->body(), 'atepast'));
    expect($withButtons)->not->toBeEmpty();
});

it('sends a lunch nudge every 30 minutes while pending but not once eaten', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 30, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $nudge = NutritionMessage::query()->where('kind', 'followup')->where('content', 'like', '%поели обед%')->get();
    expect($nudge)->toHaveCount(1);
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:13:30'))->count())->toBe(1);

    // Тот же 30-мин слот — без дублей.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 31, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%поели обед%')->count())->toBe(1);

    // Приём отмечен eaten → следующий слот (14:00) не пингует.
    NutritionMeal::query()->where('profile_id', $this->profile->id)->where('type', 'lunch')->first()->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-16 13:45:00',
    ]);
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 14, 0, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%поели обед%')->count())->toBe(1);
});

it('regenerates the reminder when the meal window is moved to a later time', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));
    // Окно обеда уже пересчитано на 13:00–14:00.
    prepareLunchWindow($this->profile);
    // По СТАРОМУ окну (11:30) напоминание уже уходило — ключ израсходован.
    NutritionSentEvent::query()->create([
        'event_key' => pkey($this->profile, '2026-07-16:meal:lunch:11:30'),
        'sent_at' => '2026-07-16 11:30:00',
    ]);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Сдвиг окна дал новый ключ 13:00 → напоминание УХОДИТ повторно.
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:13:00'))->count())->toBe(1);
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
    expect(NutritionSentEvent::query()->where('event_key', 'like', pkey($this->profile, '2026-07-16:meal:lunch:%'))->count())->toBe(1);
});

it('does not burst-backfill missed slots when the worker was down (first tick at 14:20)', function () {
    // Симуляция простоя воркера: до 14:20 тик не запускался, слот-события пусты.
    // Окно 13:00–15:00 (шире часа), чтобы 14:20 всё ещё было ВНУТРИ окна и надж
    // текущего ведра слался (границу window_end проверяет отдельный тест).
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 14, 20, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile, '2026-07-16 13:00:00', '2026-07-16 15:00:00');

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Уходит РОВНО одно напоминание по обеду — наджа текущего 30-мин ведра (14:00),
    // а не пачка за пропущенные слоты 13:00/13:30.
    expect(NutritionMessage::query()->where('content', 'like', '%поели обед%')->count())->toBe(1);
    expect(NutritionSentEvent::query()->where('event_key', 'like', pkey($this->profile, '2026-07-16:meal:lunch:%'))->count())->toBe(1);
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:14:00'))->count())->toBe(1);

    // Пропущенные слоты НЕ материализованы.
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:13:00'))->exists())->toBeFalse()
        ->and(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:13:30'))->exists())->toBeFalse();
});

it('stops nudging once the window has ended even while still in the missed grace period', function () {
    // Окно обеда 13:00–14:00; сейчас 14:30 — за window_end, но ещё в grace-периоде
    // до missed (14:00 + 90м = 15:30). Раньше здесь уходил надж «поели обед?»;
    // теперь наджи обрезаны границей окна — молчим.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 14, 30, 0, 'Europe/Moscow'));
    $lunch = prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Ни одного наджа «поели обед?» и ни одного слот-события 14:30.
    expect(NutritionMessage::query()->where('content', 'like', '%поели обед%')->count())->toBe(0);
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:14:30'))->exists())->toBeFalse();

    // Приём ещё pending — свой порог missed (window_end + 90м) не достигнут.
    expect($lunch->fresh()->status)->toBe('pending');
});

it('still sends the nudge exactly at the window end boundary', function () {
    // Ровно на window_end (14:00) окно ещё считается открытым (now <= window_end),
    // поэтому надж текущего ведра уходит; за границей (14:01+) — уже нет.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 14, 0, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    expect(NutritionMessage::query()->where('kind', 'followup')->where('content', 'like', '%поели обед%')->count())->toBe(1);
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:meal:lunch:14:00'))->count())->toBe(1);
});

it('still marks the meal missed by its own threshold after nudges have stopped', function () {
    // Наджи прекратились на window_end (14:00), но пометка missed живёт по своему
    // порогу window_end + missed_after (90м) = 15:30. Сейчас 15:31 — приём missed.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 15, 31, 0, 'Europe/Moscow'));
    $lunch = prepareLunchWindow($this->profile);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    expect($lunch->fresh()->status)->toBe('missed');
    // За границей окна наджей нет.
    expect(NutritionMessage::query()->where('content', 'like', '%поели обед%')->count())->toBe(0);
});

it('in maintenance sends only the start reminder, no pre and no nudges', function () {
    $this->profile->update(['phase' => 'maintenance']);

    // Пре-напоминание в 12:30 — не уходит.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 12, 30, 0, 'Europe/Moscow'));
    prepareLunchWindow($this->profile);
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%через полчаса%')->count())->toBe(0);

    // Старт в 13:00 — уходит.
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('kind', 'reminder')->where('content', 'like', '%Обед 13:00–14:00%')->count())->toBe(1);

    // Надж в 13:30 — не уходит (мягкий режим).
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 30, 0, 'Europe/Moscow'));
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionMessage::query()->where('content', 'like', '%поели обед%')->count())->toBe(0);
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

it('prints the profile name prefix in dry-run output', function () {
    $this->artisan('nutrition:tick', ['--at' => '2026-07-16 13:00'])
        ->expectsOutputToContain('Глеб')
        ->assertExitCode(0);
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
        ->and($checkup->content)->toContain('Глеб, разбор недели');
});

it('routes each active profile reminders and topics to its own chat', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));

    $a = $this->profile; // admin, чат 123
    $b = nutritionProfile(['telegram_user_id' => 222, 'is_admin' => false, 'main_chat_id' => 222]);

    // Обоим — свои приёмы; завтрак eaten, обед с РАЗНЫМИ окнами.
    foreach ([$a, $b] as $p) {
        Planner::ensureDay($p, CarbonImmutable::now('Europe/Moscow'));
        NutritionMeal::query()->where('profile_id', $p->id)->where('type', 'breakfast')->first()
            ->update(['status' => 'eaten', 'eaten_at' => '2026-07-16 08:00:00']);
    }
    NutritionMeal::query()->where('profile_id', $a->id)->where('type', 'lunch')->first()
        ->update(['window_start' => '2026-07-16 13:00:00', 'window_end' => '2026-07-16 14:00:00']);
    NutritionMeal::query()->where('profile_id', $b->id)->where('type', 'lunch')->first()
        ->update(['window_start' => '2026-07-16 16:00:00', 'window_end' => '2026-07-16 17:00:00']);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Состав обеда «Правило тарелки» есть только в старт-напоминании (не в приветствии).
    $reminderTo = fn (int $chat) => Http::recorded(fn ($req) => str_contains($req->url(), 'sendMessage')
        && ($req->data()['chat_id'] ?? null) === $chat
        && str_contains((string) ($req->data()['text'] ?? ''), 'Правило тарелки'));

    // Обед A (13:00) ушёл в чат A и НЕ утёк в чат B.
    expect($reminderTo(123))->toHaveCount(1)
        ->and($reminderTo(222))->toBeEmpty();

    // Событие обеда есть только у A; у B обед в 16:00 ещё не наступил.
    expect(NutritionSentEvent::query()->where('event_key', pkey($a, '2026-07-16:meal:lunch:13:00'))->exists())->toBeTrue()
        ->and(NutritionSentEvent::query()->where('event_key', pkey($b, '2026-07-16:meal:lunch:16:00'))->exists())->toBeFalse();

    // Приветствие ушло в оба чата — каждому своё.
    $greetingTo = fn (int $chat) => Http::recorded(fn ($req) => str_contains($req->url(), 'sendMessage')
        && ($req->data()['chat_id'] ?? null) === $chat
        && str_contains((string) ($req->data()['text'] ?? ''), 'План на сегодня'));
    expect($greetingTo(123))->toHaveCount(1)
        ->and($greetingTo(222))->toHaveCount(1);
});

it('sends a scheduled topic only to the profile it belongs to', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 10, 30, 0, 'Europe/Moscow'));

    $a = $this->profile; // чат 123
    $b = nutritionProfile(['telegram_user_id' => 444, 'is_admin' => false, 'main_chat_id' => 444]);

    $topic = NutritionTopic::query()->create(['title' => 'Тема дня', 'position' => 1, 'intro' => 'Интро темы дня', 'file_path' => '']);
    // Запланировано ТОЛЬКО для A на сегодня.
    NutritionTopicSend::query()->create(['profile_id' => $a->id, 'topic_id' => $topic->id, 'scheduled_on' => '2026-07-16']);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $topicTo = fn (int $chat) => Http::recorded(fn ($req) => str_contains($req->url(), 'sendMessage')
        && ($req->data()['chat_id'] ?? null) === $chat
        && str_contains((string) ($req->data()['text'] ?? ''), 'Интро темы дня'));

    expect($topicTo(123))->toHaveCount(1)
        ->and($topicTo(444))->toBeEmpty();

    // Строка выдачи A помечена sent; у B этой темы нет вовсе.
    expect(NutritionTopicSend::query()->where('profile_id', $a->id)->where('topic_id', $topic->id)->first()->sent_at)->not->toBeNull();
    expect(NutritionTopicSend::query()->where('profile_id', $b->id)->count())->toBe(0);
});

it('skips a paused profile entirely', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 13, 0, 0, 'Europe/Moscow'));

    $paused = nutritionProfile(['telegram_user_id' => 333, 'is_admin' => false, 'main_chat_id' => 333, 'status' => 'paused']);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Ни приёмов, ни сообщений для paused-профиля.
    expect(NutritionMeal::query()->where('profile_id', $paused->id)->count())->toBe(0);
    expect(Http::recorded(fn ($req) => ($req->data()['chat_id'] ?? null) === 333))->toBeEmpty();
});

it('skips a profile without main_chat_id and logs it once', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 7, 35, 0, 'Europe/Moscow'));
    $this->profile->update(['main_chat_id' => null]);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    expect(NutritionMeal::query()->count())->toBe(0)
        ->and(NutritionMessage::query()->where('direction', 'out')->count())->toBe(0);
    Http::assertNothingSent();

    // Пропуск залогирован once-событием p{id}:{d}:no-chat.
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:no-chat'))->exists())->toBeTrue();

    // Повторный тик не плодит дублей события.
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:no-chat'))->count())->toBe(1);
});

it('skips an active profile until the program is started and then ticks normally', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 7, 35, 0, 'Europe/Moscow'));
    // Анкета завершена (status=active, чат есть), но «Начать программу» не нажата.
    $this->profile->update(['program_started_on' => null]);

    $this->artisan('nutrition:tick')->assertExitCode(0);

    // Ни приёмов, ни исходящих сообщений — тик молчит до старта программы.
    expect(NutritionMeal::query()->count())->toBe(0)
        ->and(outCount())->toBe(0);
    Http::assertNothingSent();

    // Единственный след — once-событие p{id}:{d}:not-started.
    expect(NutritionSentEvent::query()->where('event_key', pkey($this->profile, '2026-07-16:not-started'))->exists())->toBeTrue()
        ->and(NutritionSentEvent::query()->count())->toBe(1);

    // Повторный тик не плодит дублей события.
    $this->artisan('nutrition:tick')->assertExitCode(0);
    expect(NutritionSentEvent::query()->count())->toBe(1);

    // Dry-run честно объявляет пропуск и ничего не пишет.
    $this->artisan('nutrition:tick', ['--at' => '2026-07-16 13:00'])
        ->expectsOutputToContain('программа не начата')
        ->assertExitCode(0);
    expect(NutritionSentEvent::query()->count())->toBe(1);

    // После нажатия «Начать программу» тик работает как обычно.
    $this->profile->update(['program_started_on' => '2026-07-16']);
    $this->artisan('nutrition:tick')->assertExitCode(0);

    $kinds = NutritionMessage::query()->where('direction', 'out')->pluck('kind')->all();
    expect($kinds)->toContain('greeting')
        ->and(NutritionMeal::query()->count())->toBeGreaterThan(0);
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
        $send = NutritionTopicSend::query()->create([
            'profile_id' => $this->profile->id,
            'topic_id' => $topic->id,
            'scheduled_on' => '2026-07-16',
        ]);

        app(SendTopic::class)->handle($this->profile, $send);
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
        ->and($send->fresh()->sent_at)->not->toBeNull();
});

it('falls back to intro text when none of the topic files exist on disk', function () {
    $topic = NutritionTopic::query()->create([
        'title' => 'Тема без файлов',
        'file_path' => '__test_absent_one.pdf|__test_absent_two.pdf',
        'intro' => 'Интро без файлов',
        'position' => 2,
    ]);
    $send = NutritionTopicSend::query()->create([
        'profile_id' => $this->profile->id,
        'topic_id' => $topic->id,
        'scheduled_on' => '2026-07-16',
    ]);

    app(SendTopic::class)->handle($this->profile, $send);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendDocument'));

    $message = NutritionMessage::query()->where('kind', 'topic')->first();
    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('Интро без файлов');

    expect($send->fresh()->sent_at)->not->toBeNull();
});

it('isolates a failing profile so the tick still reminds the others', function () {
    // Битый профиль обрабатывается первым (меньший id) и роняет tickProfile;
    // здоровый профиль всё равно должен получить утреннее приветствие.
    NutritionProfile::query()->delete();

    $this->travelTo(CarbonImmutable::create(2026, 7, 15, 8, 0, 0, 'Europe/Moscow'));

    $broken = nutritionProfile([
        'telegram_user_id' => 111,
        'is_admin' => false,
        'main_chat_id' => 999,
    ]);
    // default_windows со строками вместо массивов → TypeError в Planner::ensureDay.
    $broken->settings = ['default_windows' => [
        'breakfast' => 'x', 'lunch' => 'x', 'snack' => 'x', 'dinner' => 'x',
    ]];
    $broken->save();

    $healthy = nutritionProfile([
        'telegram_user_id' => 222,
        'is_admin' => false,
        'main_chat_id' => 555,
    ]);

    expect($broken->id)->toBeLessThan($healthy->id);

    Log::spy();

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $greeting = NutritionMessage::query()
        ->where('profile_id', $healthy->id)
        ->where('kind', 'greeting')
        ->exists();

    expect($greeting)->toBeTrue();

    // Сбой залогирован с id битого профиля.
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context) => $message === 'nutrition: tick profile failed'
            && ($context['profile_id'] ?? null) === $broken->id)
        ->once();
});

it('addresses the client by name in the morning greeting', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 16, 7, 35, 0, 'Europe/Moscow'));

    $this->artisan('nutrition:tick')->assertExitCode(0);

    $greeting = NutritionMessage::query()
        ->where('direction', 'out')
        ->where('kind', 'greeting')
        ->value('content');

    expect($greeting)->not->toBeNull()
        ->and($greeting)->toStartWith('Глеб,')
        ->and($greeting)->toContain('План на сегодня');
});
