<?php

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandlePhoto;
use App\Actions\Nutrition\HandleQuestion;
use App\Actions\Nutrition\RunDaySummary;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
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
});

/** Телеграм-фейки + vision, отвечающий заданным текстом (JSON или сырой). */
function fakeVision(string $visionText): void
{
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
        'api.telegram.org/file/*' => Http::response('BINARYIMAGE'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $visionText]]]),
    ]);
}

function fakeClassifier(array $json): void
{
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]]),
    ]);
}

function lastOut(): ?string
{
    return NutritionMessage::query()->where('direction', 'out')->orderByDesc('id')->value('content');
}

it('saves score and rating breakdown from a food photo with strict JSON', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);

    fakeVision(json_encode([
        'feedback' => 'Отличный обед, Настя довольна! 🙌🏼',
        'score' => 8,
        'composition_ok' => true,
        'forbidden' => [],
        'comment' => 'баланс хороший',
    ], JSON_UNESCAPED_UNICODE));

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->score)->toBe(8)
        ->and($lunch->ai_feedback)->toBe('Отличный обед, Настя довольна! 🙌🏼')
        ->and($lunch->rating['composition_ok'])->toBeTrue()
        ->and($lunch->rating['forbidden'])->toBe([])
        ->and($lunch->rating['comment'])->toBe('баланс хороший')
        ->and($lunch->rating['interval_ok'])->toBeTrue()
        ->and($lunch->rating['window_ok'])->toBeTrue();

    // Пользователь видит фидбек, а не сырой JSON.
    expect(lastOut())->toContain('Настя довольна')->not->toContain('composition_ok');
});

it('appends the shifted dinner window to a direct food photo reply', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 16, 56, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    // Завтрак и обед уже съедены → полдник — единственный кандидат на фото.
    NutritionMeal::query()->whereIn('type', ['breakfast', 'lunch'])
        ->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 12:00:00']);

    fakeVision('Идеально! 🙌🏼');

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    expect(NutritionMeal::query()->where('type', 'snack')->value('status'))->toBe('eaten');
    // Полдник в 16:56 сдвигает ужин на 19:56–20:56 — хвост в ответе.
    expect(lastOut())->toContain('Ужин теперь')->toContain('19:56');
});

it('records the meal with null score when the vision reply is not JSON', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);

    fakeVision('Идеально! 🙌🏼');

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->score)->toBeNull()
        ->and($lunch->ai_feedback)->toBe('Идеально! 🙌🏼')
        // Детерминированные поля пишутся всегда; без ИИ-структуры composition отсутствует.
        ->and($lunch->rating)->toHaveKey('interval_ok')
        ->and($lunch->rating)->toHaveKey('window_ok')
        ->and($lunch->rating)->not->toHaveKey('composition_ok');
    expect(lastOut())->toContain('Идеально');
});

it('rates the first meal of the day as interval_ok and window_ok true', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 8, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    $breakfast = NutritionMeal::query()->where('type', 'breakfast')->first();
    Planner::markEaten($this->profile, $breakfast, CarbonImmutable::create(2026, 7, 13, 8, 0, 0, 'Europe/Moscow'), null, null);

    $breakfast->refresh();
    expect($breakfast->score)->toBeNull()
        ->and($breakfast->rating)->toBe(['interval_ok' => true, 'window_ok' => true]);
});

it('rates interval_ok false when a meal follows the previous one too soon', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    $breakfast = NutritionMeal::query()->where('type', 'breakfast')->first();
    Planner::markEaten($this->profile, $breakfast, CarbonImmutable::create(2026, 7, 13, 10, 0, 0, 'Europe/Moscow'), null, null);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    // 10:00 → 11:30 = 1.5 ч < 2.5 ч.
    Planner::markEaten($this->profile, $lunch, CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'), null, null);

    $lunch->refresh();
    // 90 мин между приёмами < 2.5 ч → интервал не ок.
    expect($lunch->rating['interval_ok'])->toBeFalse();
});

it('rates window_ok false when a meal is eaten outside its window', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 13, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    // Окно обеда 11:00–12:30 (+15 мин грейс = 12:45); 13:00 — вне окна.
    Planner::markEaten($this->profile, $lunch, CarbonImmutable::create(2026, 7, 13, 13, 0, 0, 'Europe/Moscow'), null, null);

    $lunch->refresh();
    expect($lunch->rating['window_ok'])->toBeFalse()
        // Первый съеденный приём дня → интервал ок.
        ->and($lunch->rating['interval_ok'])->toBeTrue();
});

it('saves the score from a text meal report via MealIntent', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    fakeClassifier([
        'intent' => 'meal_report',
        'reports' => [[
            'meal' => 'lunch',
            'time' => '11:15',
            'food' => 'курица с салатом и гречкой',
            'score' => 7,
            'composition_ok' => true,
            'forbidden' => [],
            'comment' => 'по правилу тарелки',
        ]],
        'reply' => 'Хороший обед! 🙌🏼',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'пообедал в 11:15 — курица с салатом и гречкой']], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->score)->toBe(7)
        ->and($lunch->rating['composition_ok'])->toBeTrue()
        ->and($lunch->rating)->toHaveKey('interval_ok')
        ->and($lunch->rating)->toHaveKey('window_ok');
});

it('treats a future-intent message as a question and records no meal', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    fakeClassifier([
        'intent' => 'question',
        'reports' => [],
        'reply' => 'Гречка, курица и сыр — отличный вариант на обед! 👌🏻',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'Собираюсь на обед: гречка, курица, сыр — норм?']], $this->profile);

    expect(NutritionMeal::query()->where('status', 'eaten')->count())->toBe(0);

    // Guard про будущее время присутствует в промпте классификатора.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'о будущем приёме'));
});

it('includes the average day score in the summary prompt', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 22, 35, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    $breakfast = NutritionMeal::query()->where('type', 'breakfast')->first();
    Planner::markEaten($this->profile, $breakfast, CarbonImmutable::create(2026, 7, 13, 8, 0, 0, 'Europe/Moscow'), null, null, 8, ['composition_ok' => true, 'forbidden' => [], 'comment' => '']);
    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    Planner::markEaten($this->profile, $lunch, CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'), null, null, 6, ['composition_ok' => false, 'forbidden' => ['сахар'], 'comment' => '']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Итог дня: молодец!']]]),
    ]);

    app(RunDaySummary::class)->handle($this->profile, CarbonImmutable::now('Europe/Moscow'), 123);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Средний балл'));
});

it('sends the feedback text and keeps extra when the vision score is out of range', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);

    fakeVision(json_encode([
        'feedback' => 'Хороший обед, но десерт лишний 👌🏻',
        'score' => 15,
        'composition_ok' => false,
        'forbidden' => ['сахар'],
        'comment' => 'десерт',
    ], JSON_UNESCAPED_UNICODE));

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->score)->toBeNull()
        ->and($lunch->ai_feedback)->toBe('Хороший обед, но десерт лишний 👌🏻')
        ->and($lunch->rating['composition_ok'])->toBeFalse()
        ->and($lunch->rating['forbidden'])->toBe(['сахар']);

    // Пользователю уходит текст из feedback-поля, НЕ сырой JSON.
    expect(lastOut())->toContain('десерт лишний')
        ->not->toContain('"score"')
        ->not->toContain('composition_ok');
});

it('sends the feedback text when the vision JSON has no score at all', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);

    fakeVision(json_encode([
        'feedback' => 'Отличный обед по тарелке! 🙌🏼',
        'composition_ok' => true,
        'forbidden' => [],
    ], JSON_UNESCAPED_UNICODE));

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->score)->toBeNull()
        ->and($lunch->ai_feedback)->toBe('Отличный обед по тарелке! 🙌🏼')
        ->and($lunch->rating['composition_ok'])->toBeTrue();

    expect(lastOut())->toContain('по тарелке')->not->toContain('{');
});

it('saves score and rating from a mealphoto callback with strict JSON', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'missed']);
    $this->profile->setWaiting('meal_photo', 'big');

    fakeVision(json_encode([
        'feedback' => 'Поздний завтрак, но состав отличный! 🙌🏼',
        'score' => 9,
        'composition_ok' => true,
        'forbidden' => [],
        'comment' => 'овсянка с ягодами',
    ], JSON_UNESCAPED_UNICODE));

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbr',
        'data' => 'mealphoto:breakfast',
    ]], $this->profile);

    $breakfast = NutritionMeal::query()->where('type', 'breakfast')->first();
    expect($breakfast->status)->toBe('eaten')
        ->and($breakfast->score)->toBe(9)
        ->and($breakfast->ai_feedback)->toBe('Поздний завтрак, но состав отличный! 🙌🏼')
        ->and($breakfast->rating['composition_ok'])->toBeTrue()
        ->and($breakfast->rating['comment'])->toBe('овсянка с ягодами')
        ->and($breakfast->rating)->toHaveKey('interval_ok')
        ->and($breakfast->rating)->toHaveKey('window_ok');

    expect($this->profile->fresh()->waiting('meal_photo'))->toBeNull();
    expect(lastOut())->toContain('состав отличный')->not->toContain('"score"');
});
