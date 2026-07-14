<?php

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandlePhoto;
use App\Actions\Nutrition\HandleQuestion;
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

/** Тело ответа Anthropic из массива (кодируется в JSON) или сырой строки. */
function cancelMsg(array|string $payload): array
{
    $text = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

    return ['content' => [['type' => 'text', 'text' => $text]]];
}

function cancelLastOut(): ?string
{
    return NutritionMessage::query()->where('direction', 'out')->orderByDesc('id')->value('content');
}

/** Съеденный сегодня приём $type с оценкой $score (по markEaten, окна пересчитаны). */
function seedEatenCancel(object $profile, string $type, int $hour, ?int $score, string $feedback): NutritionMeal
{
    Planner::ensureDay($profile, CarbonImmutable::now('Europe/Moscow'));
    $meal = NutritionMeal::query()->where('profile_id', $profile->id)->where('type', $type)->first();
    Planner::markEaten(
        $profile,
        $meal,
        CarbonImmutable::create(2026, 7, 13, $hour, 0, 0, 'Europe/Moscow'),
        null,
        $feedback,
        $score,
        ['composition_ok' => false, 'forbidden' => [], 'comment' => 'по фото'],
    );

    return $meal->fresh();
}

it('cancels a logged breakfast from text: back to pending, fields cleared, windows recalculated', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 9, 30, 0, 'Europe/Moscow'));
    // Завтрак съеден в 09:00 (позже дефолтного окна) → обед сдвинут на 12:00–13:00.
    $breakfast = seedEatenCancel($this->profile, 'breakfast', 9, 6, 'Неплохой завтрак 👌🏻');
    $breakfast->update(['photo_file_id' => 'oldphoto']);

    expect(NutritionMeal::query()->where('type', 'lunch')->value('window_start')->format('H:i'))->toBe('12:00');

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(cancelMsg([
            'intent' => 'cancel_meal', 'reports' => [], 'reply' => '', 'target' => 'breakfast', 'resend_photo' => false,
        ])),
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'отмени завтрак']], $this->profile);

    $breakfast->refresh();
    expect($breakfast->status)->toBe('pending')
        ->and($breakfast->eaten_at)->toBeNull()
        ->and($breakfast->photo_file_id)->toBeNull()
        ->and($breakfast->score)->toBeNull()
        ->and($breakfast->ai_feedback)->toBeNull()
        ->and($breakfast->rating)->toBeNull();

    // Окно обеда вернулось к дефолту (11:00) — откат сдвига от съеденного завтрака.
    expect(NutritionMeal::query()->where('type', 'lunch')->value('window_start')->format('H:i'))->toBe('11:00');
    expect(cancelLastOut())->toContain('отменил Завтрак')->toContain('окна пересчитал');
});

it('cancels via the cancel button and softly refuses a foreign or missing id', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 30, 0, 'Europe/Moscow'));
    $lunch = seedEatenCancel($this->profile, 'lunch', 12, 7, 'Хороший обед 👌🏻');

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    // Кнопка «↩️ Отменить» → сброс приёма + подтверждение (без повторного запроса).
    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbc', 'data' => 'cancel:'.$lunch->id,
    ]], $this->profile);

    $lunch->refresh();
    expect($lunch->status)->toBe('pending')
        ->and($lunch->eaten_at)->toBeNull()
        ->and($lunch->score)->toBeNull()
        ->and($lunch->ai_feedback)->toBeNull();
    expect(cancelLastOut())->toContain('отменил Обед')->toContain('окна пересчитал');

    // Чужой приём другого профиля: его id НЕ должен отменяться из этого профиля.
    $other = nutritionProfile(['telegram_user_id' => 222, 'main_chat_id' => 222, 'is_admin' => false]);
    $otherDinner = seedEatenCancel($other, 'dinner', 19, 8, 'Ужин другого профиля 👌🏻');

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbf', 'data' => 'cancel:'.$otherDinner->id,
    ]], $this->profile);

    // Мягкий отказ; чужой приём остался eaten (доступа к нему нет).
    expect(cancelLastOut())->toContain('Не нашёл этот приём');
    expect($otherDinner->fresh()->status)->toBe('eaten');
});

it('cancels with a photo-resend promise and the next photo overwrites that exact meal', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 20, 5, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    // Реальный кейс: фото полдника пришло в окне ужина. Полдник ошибочно разобран,
    // ужин ещё открыт (20:00–21:00). Клиент: «щас пришлю другое фото» про полдник.
    NutritionMeal::query()->where('type', 'snack')->update([
        'status' => 'eaten', 'eaten_at' => '2026-07-13 18:30:00',
        'photo_file_id' => 'oldsnack', 'score' => 5, 'ai_feedback' => 'старый разбор',
    ]);
    NutritionMeal::query()->where('type', 'dinner')->update([
        'status' => 'pending', 'window_start' => '2026-07-13 20:00:00', 'window_end' => '2026-07-13 21:00:00',
    ]);
    $snackId = NutritionMeal::query()->where('type', 'snack')->value('id');

    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
        'api.telegram.org/file/*' => Http::response('BINARYIMAGE'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::sequence()
            // Фаза 1 (текст): классификатор → cancel_meal + resend_photo.
            ->push(cancelMsg(['intent' => 'cancel_meal', 'reports' => [], 'reply' => '', 'target' => 'snack', 'resend_photo' => true]))
            // Фаза 2 (фото): vision-разбор нового фото полдника.
            ->push(cancelMsg(['feedback' => 'Куриная грудка с овощами — отличный перекус! 🙌🏼', 'score' => 9, 'composition_ok' => true, 'forbidden' => [], 'comment' => ''])),
    ]);

    // Фаза 1: сброс + ожидание replace_photo на полдник.
    app(HandleQuestion::class)->handle(['message' => ['text' => 'щас пришлю другое фото']], $this->profile);

    expect($this->profile->fresh()->waiting('replace_photo'))->toBe($snackId);
    expect(NutritionMeal::query()->where('type', 'snack')->value('status'))->toBe('pending');
    expect(cancelLastOut())->toContain('жду новое фото');

    // Фаза 2: следующее ФОТО перезаписывает ИМЕННО полдник (а не спрашивает/плодит новый).
    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'newsnack']],
    ]], $this->profile->fresh());

    $snack = NutritionMeal::query()->where('type', 'snack')->first();
    expect($snack->status)->toBe('eaten')
        ->and($snack->photo_file_id)->toBe('newsnack')
        ->and($snack->score)->toBe(9)
        ->and($snack->ai_feedback)->toContain('грудка');

    // Ожидание снято; новый приём не создан; «перекусов нет» не отбито; дизамбигуацией не спрошено.
    expect($this->profile->fresh()->waiting('replace_photo'))->toBeNull();
    expect(NutritionMeal::query()->where('profile_id', $this->profile->id)->whereDate('date', '2026-07-13')->count())->toBe(4);
    expect(cancelLastOut())->not->toContain('Перекусов')->not->toContain('какой приём');
});

it('routes a composition correction to reeval and a cancel request to a reset', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 19, 30, 0, 'Europe/Moscow'));
    $lunch = seedEatenCancel($this->profile, 'lunch', 12, 2, 'Похоже на паштет — спорно 🤔');
    $dinner = seedEatenCancel($this->profile, 'dinner', 19, 4, 'Тяжеловато на ужин 🤔');

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::sequence()
            // Фаза 1: поправка состава → correct_meal (target lunch) + переоценка.
            ->push(cancelMsg(['intent' => 'correct_meal', 'reports' => [], 'reply' => '', 'target' => 'lunch']))
            ->push(cancelMsg(['feedback' => 'Куриная грудка су-вид — чистый белок! 🙌🏼', 'score' => 9, 'composition_ok' => true, 'forbidden' => [], 'comment' => '']))
            // Фаза 2: удаление приёма → cancel_meal (target dinner).
            ->push(cancelMsg(['intent' => 'cancel_meal', 'reports' => [], 'reply' => '', 'target' => 'dinner', 'resend_photo' => false])),
    ]);

    // Поправка состава → ПЕРЕОЦЕНКА, приём остаётся eaten (не удаляется).
    app(HandleQuestion::class)->handle(['message' => ['text' => 'это куриная грудка су-вид, а не паштет']], $this->profile);

    $lunch->refresh();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->score)->toBe(9)
        ->and($lunch->rating['reevaluated'])->toBeTrue();
    expect(cancelLastOut())->toContain('пересчитал Обед');

    // Удаление → СБРОС в pending, приём НЕ переоценивается.
    app(HandleQuestion::class)->handle(['message' => ['text' => 'отмени ужин']], $this->profile);

    $dinner->refresh();
    expect($dinner->status)->toBe('pending')
        ->and($dinner->eaten_at)->toBeNull()
        ->and($dinner->score)->toBeNull();
    expect(cancelLastOut())->toContain('отменил Ужин');

    // В промпте классификатора есть подсказка cancel_meal (разобранный приём есть).
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'cancel_meal'));
});

it('softly refuses a not-logged named meal and does not cancel on a future-intent question', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 30, 0, 'Europe/Moscow'));
    $lunch = seedEatenCancel($this->profile, 'lunch', 12, 7, 'Хороший обед 👌🏻');

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::sequence()
            // Завтрак сегодня не фиксировали → cancel_meal(target breakfast) — отменять нечего.
            ->push(cancelMsg(['intent' => 'cancel_meal', 'reports' => [], 'reply' => '', 'target' => 'breakfast', 'resend_photo' => false]))
            // Будущее/намерение → остаётся question, отмену НЕ триггерит.
            ->push(cancelMsg(['intent' => 'question', 'reports' => [], 'reply' => 'На ужин лучше белок — рыба или индейка 👌🏻', 'target' => null, 'resend_photo' => false])),
    ]);

    // Названный тип не фиксировали → мягкий отказ, ничего не сброшено.
    app(HandleQuestion::class)->handle(['message' => ['text' => 'отмени завтрак']], $this->profile);

    expect(NutritionMeal::query()->where('type', 'breakfast')->value('status'))->toBe('pending');
    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('eaten');
    expect(cancelLastOut())->toContain('завтрак')->toContain('отменять нечего');

    // Вопрос о будущем → обычный ответ; обед не тронут.
    app(HandleQuestion::class)->handle(['message' => ['text' => 'что съесть на ужин попозже?']], $this->profile);

    $lunch->refresh();
    expect($lunch->status)->toBe('eaten')->and($lunch->score)->toBe(7);
    expect(cancelLastOut())->toContain('лучше белок');
});

it('restores later pending meal windows when an early meal is cancelled', function () {
    $now = CarbonImmutable::create(2026, 7, 13, 13, 0, 0, 'Europe/Moscow');
    $this->travelTo($now);
    Planner::ensureDay($this->profile, $now);

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    // Обед съеден поздно (13:00) → полдник сдвинут на 16:00–17:00 (anchor+3/+4ч).
    Planner::markEaten($this->profile, $lunch, $now, null, 'обед', 7, ['composition_ok' => true, 'forbidden' => [], 'comment' => '']);

    expect(NutritionMeal::query()->where('type', 'snack')->value('window_start')->format('H:i'))->toBe('16:00');

    // Отмена обеда возвращает окно полдника к дефолту (14:40–16:10).
    Planner::cancelMeal($this->profile, $lunch->fresh());

    $snack = NutritionMeal::query()->where('type', 'snack')->first();
    expect($snack->window_start->format('H:i'))->toBe('14:40')
        ->and($snack->window_end->format('H:i'))->toBe('16:10');

    $lunch->refresh();
    expect($lunch->status)->toBe('pending')->and($lunch->eaten_at)->toBeNull();
});
