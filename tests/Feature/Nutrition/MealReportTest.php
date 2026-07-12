<?php

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandlePhoto;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
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

/**
 * Ставит фейки Telegram + Anthropic. Для vision-запросов (есть image-блок)
 * всегда возвращаем реакцию на еду; для текстовых — переданный JSON классификатора
 * (или дефолтную «Идеально!», если $classifyJson === null — имитируем невалидный JSON).
 */
function fakeApis(?array $classifyJson): void
{
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
        'api.telegram.org/file/*' => Http::response('BINARYIMAGE'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => function ($request) use ($classifyJson) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            if (str_contains($body, '"type":"image"')) {
                return Http::response(['content' => [['type' => 'text', 'text' => 'Идеально! 🙌🏼']]]);
            }

            $text = $classifyJson === null
                ? 'Идеально! 🙌🏼'
                : json_encode($classifyJson, JSON_UNESCAPED_UNICODE);

            return Http::response(['content' => [['type' => 'text', 'text' => $text]]]);
        },
    ]);
}

function outText(): ?string
{
    return NutritionMessage::query()->where('direction', 'out')->orderByDesc('id')->value('content');
}

it('logs breakfast from free text when it was missed and shifts lunch', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'missed']);

    fakeApis([
        'intent' => 'meal_report',
        'reports' => [['meal' => 'breakfast', 'time' => '10:00', 'food' => 'овсянка+груша']],
        'reply' => 'Отличный завтрак! 🙌🏼',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'забыл сфоткать, позавтракал в 10:00, овсянка+груша']], $this->profile);

    $breakfast = NutritionMeal::query()->where('type', 'breakfast')->first();
    expect($breakfast->status)->toBe('eaten')
        ->and($breakfast->eaten_at->format('H:i'))->toBe('10:00');

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->window_start->format('H:i'))->toBe('13:00');
    expect(outText())->toContain('13:00');
});

it('answers a question without touching meals', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 8, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    fakeApis([
        'intent' => 'question',
        'reports' => [],
        'reply' => 'На завтрак — сложные углеводы плюс фрукт 👌🏻',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'что съесть на завтрак?']], $this->profile);

    expect(NutritionMeal::query()->where('status', 'eaten')->count())->toBe(0);
    expect(outText())->toContain('сложные углеводы');
});

it('logs two meals from one message and shifts snack', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 15, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    fakeApis([
        'intent' => 'meal_report',
        'reports' => [
            ['meal' => 'breakfast', 'time' => '10:00', 'food' => 'овсянка'],
            ['meal' => 'lunch', 'time' => '14:00', 'food' => 'курица с салатом'],
        ],
        'reply' => 'Хорошо поел! 🙌🏼',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'позавтракал в 10 овсянкой, а в 14 обед — курица с салатом']], $this->profile);

    expect(NutritionMeal::query()->where('type', 'breakfast')->value('status'))->toBe('eaten')
        ->and(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('eaten');

    $snack = NutritionMeal::query()->where('type', 'snack')->first();
    expect($snack->window_start->format('H:i'))->toBe('17:00');
    expect(outText())->toContain('Полдник');
});

it('asks which meal a late food photo belongs to when a meal was missed', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'missed']);
    fakeApis(null);

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    expect(NutritionMeal::query()->where('status', 'eaten')->count())->toBe(0);
    expect($this->profile->fresh()->waiting('meal_photo'))->toBe('big');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains((string) ($r['reply_markup'] ?? ''), 'mealphoto:breakfast')
        && str_contains((string) ($r['reply_markup'] ?? ''), 'mealphoto:lunch'));
    // Vision не вызывали до выбора приёма.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com'));
});

it('logs the chosen meal from a mealphoto callback and clears the pending photo', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'missed']);
    $this->profile->setWaiting('meal_photo', 'big');
    fakeApis(null);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbm',
        'data' => 'mealphoto:breakfast',
    ]], $this->profile);

    $breakfast = NutritionMeal::query()->where('type', 'breakfast')->first();
    expect($breakfast->status)->toBe('eaten')
        ->and($breakfast->photo_file_id)->toBe('big')
        ->and($breakfast->ai_feedback)->toBe('Идеально! 🙌🏼');

    expect($this->profile->fresh()->waiting('meal_photo'))->toBeNull();

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->window_start->format('H:i'))->not->toBe('11:00');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/answerCallbackQuery'));
});

it('logs a meal via atepast then a typed time', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 14, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    fakeApis(null);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbp',
        'data' => 'atepast:lunch',
    ]], $this->profile);
    expect($this->profile->fresh()->waiting('meal_time'))->toBe('lunch');

    app(HandleQuestion::class)->handle(['message' => ['text' => '13:30']], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->eaten_at->format('H:i'))->toBe('13:30');

    $snack = NutritionMeal::query()->where('type', 'snack')->first();
    expect($snack->window_start->format('H:i'))->toBe('16:30');
    expect($this->profile->fresh()->waiting('meal_time'))->toBeNull();
});

it('logs a food photo directly when only the current meal is a candidate', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-13 08:00:00',
    ]);
    fakeApis(null);

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->photo_file_id)->toBe('big')
        ->and($lunch->ai_feedback)->toBe('Идеально! 🙌🏼');
    expect($this->profile->fresh()->waiting('meal_photo'))->toBeNull();
});

it('marks a missed meal eaten from an ate callback', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'missed']);
    fakeApis(null);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cba',
        'data' => 'ate:breakfast',
    ]], $this->profile);

    expect(NutritionMeal::query()->where('type', 'breakfast')->value('status'))->toBe('eaten');
});

it('drops a stale meal-time wait when a later request clobbers it', function () {
    // «Поел раньше» выставил meal_time, но тик успел прислать metrics_request:
    // ответ юзера «11500» относится к шагам, а не ко времени приёма.
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 21, 40, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    $this->profile->setWaiting('meal_time', 'lunch');
    NutritionMessage::query()->create(['profile_id' => $this->profile->id, 'direction' => 'out', 'kind' => 'metrics_request', 'content' => 'Шаги?']);
    fakeApis(null);

    app(HandleNumbers::class)->handle(['message' => ['text' => '11500']], $this->profile);

    expect((int) NutritionMetric::query()->where('type', 'steps')->value('value'))->toBe(11500);
    expect($this->profile->fresh()->waiting('meal_time'))->toBeNull();
    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('pending');
});

it('does not overwrite an already eaten meal from a text report', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-13 08:10:00',
        'photo_file_id' => 'orig',
        'ai_feedback' => 'Отличный завтрак!',
    ]);

    fakeApis([
        'intent' => 'meal_report',
        'reports' => [['meal' => 'breakfast', 'time' => '10:00', 'food' => 'овсянка']],
        'reply' => 'Супер! 🙌🏼',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'да я утром позавтракал в 10 овсянкой']], $this->profile);

    $breakfast = NutritionMeal::query()->where('type', 'breakfast')->first();
    expect($breakfast->eaten_at->format('H:i'))->toBe('08:10')
        ->and($breakfast->photo_file_id)->toBe('orig')
        ->and($breakfast->ai_feedback)->toBe('Отличный завтрак!');
    expect(outText())->toContain('уже отмечен');
});

it('does not overwrite an already eaten meal via atepast time entry', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 14, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'lunch')->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-13 12:30:00',
        'ai_feedback' => 'Хороший обед!',
    ]);
    fakeApis(null);

    app(HandleCallback::class)->handle(['callback_query' => ['id' => 'cbpp', 'data' => 'atepast:lunch']], $this->profile);
    app(HandleQuestion::class)->handle(['message' => ['text' => '13:00']], $this->profile);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->eaten_at->format('H:i'))->toBe('12:30')
        ->and($lunch->ai_feedback)->toBe('Хороший обед!');
    expect($this->profile->fresh()->waiting('meal_time'))->toBeNull();
    expect(outText())->toContain('уже отмечен');
});

it('records the resolvable meal and asks about the ambiguous one in a mixed report', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 15, 0, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    fakeApis([
        'intent' => 'meal_report',
        'reports' => [
            ['meal' => 'breakfast', 'time' => '10:00', 'food' => 'овсянка'],
            ['meal' => null, 'time' => null, 'food' => 'что-то ещё'],
        ],
        'reply' => 'Принял! 🙌🏼',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'позавтракал овсянкой в 10 и ещё что-то съел']], $this->profile);

    expect(NutritionMeal::query()->where('type', 'breakfast')->value('status'))->toBe('eaten');
    expect(outText())->toContain('Какой это приём?');
});
