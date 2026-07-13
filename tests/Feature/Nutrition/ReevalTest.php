<?php

use App\Actions\Nutrition\HandleCallback;
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
function reevalMsg(array|string $payload): array
{
    $text = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

    return ['content' => [['type' => 'text', 'text' => $text]]];
}

function reevalLastOut(): ?string
{
    return NutritionMessage::query()->where('direction', 'out')->orderByDesc('id')->value('content');
}

/** Съеденный сегодня приём $type с исходной оценкой $score (по markEaten). */
function seedEatenMeal(object $profile, string $type, int $hour, ?int $score, string $feedback): NutritionMeal
{
    Planner::ensureDay($profile, CarbonImmutable::now('Europe/Moscow'));
    $meal = NutritionMeal::query()->where('type', $type)->first();
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

it('re-evaluates a meal from a text correction without moving eaten_at or windows', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 10, 0, 'Europe/Moscow'));
    // Обед разобран по фото с низким баллом 2 (vision ошибся).
    $lunch = seedEatenMeal($this->profile, 'lunch', 12, 2, 'Похоже на молочный паштет — состав спорный 🤔');

    $eatenAt = $lunch->eaten_at->format('Y-m-d H:i:s');
    $winStart = $lunch->window_start->format('Y-m-d H:i:s');
    $winEnd = $lunch->window_end->format('Y-m-d H:i:s');

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::sequence()
            ->push(reevalMsg(['intent' => 'correct_meal', 'reports' => [], 'reply' => '', 'target' => null]))
            ->push(reevalMsg([
                'feedback' => 'Куриная грудка су-вид — отличный чистый белок! 🙌🏼',
                'score' => 9,
                'composition_ok' => true,
                'forbidden' => [],
                'comment' => 'уточнение клиента: курогрудь су-вид',
            ])),
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'это не паштет, а куриная грудка су-вид']], $this->profile);

    $lunch->refresh();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->score)->toBe(9)
        ->and($lunch->ai_feedback)->toContain('су-вид')
        ->and($lunch->rating['composition_ok'])->toBeTrue()
        ->and($lunch->rating['reevaluated'])->toBeTrue()
        // Время приёма и окна НЕ сдвинулись.
        ->and($lunch->eaten_at->format('Y-m-d H:i:s'))->toBe($eatenAt)
        ->and($lunch->window_start->format('Y-m-d H:i:s'))->toBe($winStart)
        ->and($lunch->window_end->format('Y-m-d H:i:s'))->toBe($winEnd);

    expect(reevalLastOut())->toContain('пересчитал Обед')->toContain('9/10')->not->toContain('composition_ok');
});

it('re-evaluates the named meal on an explicit request and softly refuses a missing type', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 18, 30, 0, 'Europe/Moscow'));
    // Съеден только ужин; завтрак сегодня не разбирался.
    seedEatenMeal($this->profile, 'dinner', 18, 4, 'Тяжеловато на ужин 🤔');

    // Одна фикстура на обе фазы: classify(dinner) + reevaluate + classify(breakfast-refuse).
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::sequence()
            ->push(reevalMsg(['intent' => 'correct_meal', 'reports' => [], 'reply' => '', 'target' => 'dinner']))
            ->push(reevalMsg(['feedback' => 'Чистый белок на ужин — то что нужно 👌🏻', 'score' => 8, 'composition_ok' => true, 'forbidden' => [], 'comment' => '']))
            ->push(reevalMsg(['intent' => 'correct_meal', 'reports' => [], 'reply' => '', 'target' => 'breakfast'])),
    ]);

    // «переоцени ужин» → пересчёт ужина.
    app(HandleQuestion::class)->handle(['message' => ['text' => 'переоцени ужин, это была запечённая рыба без масла']], $this->profile);

    expect(NutritionMeal::query()->where('type', 'dinner')->value('score'))->toBe(8);
    expect(reevalLastOut())->toContain('пересчитал Ужин')->toContain('8/10');

    // «переоцени завтрак» — завтрак не разбирался → мягкий отказ, без второго вызова модели.
    app(HandleQuestion::class)->handle(['message' => ['text' => 'переоцени завтрак']], $this->profile);

    expect(reevalLastOut())->toContain('не вижу сегодня')->toContain('Завтрак');
    expect(NutritionMeal::query()->where('type', 'breakfast')->value('status'))->toBe('pending');
});

it('routes the reeval button to a waiting state and re-evaluates the next text for that meal', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 10, 0, 'Europe/Moscow'));
    $lunch = seedEatenMeal($this->profile, 'lunch', 12, 2, 'Спорный состав 🤔');

    // Одна фикстура: кнопка (telegram-only) + переоценка текстом (anthropic).
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(reevalMsg([
            'feedback' => 'Индейка на гриле — отличный белок! 🙌🏼',
            'score' => 9,
            'composition_ok' => true,
            'forbidden' => [],
            'comment' => '',
        ])),
    ]);

    app(HandleCallback::class)->handle(['callback_query' => [
        'id' => 'cbr',
        'data' => 'reeval:'.$lunch->id,
    ]], $this->profile);

    expect($this->profile->fresh()->waiting('reeval'))->toBe($lunch->id);
    expect(reevalLastOut())->toContain('Что там на самом деле');

    // Следующий текст → переоценка ИМЕННО этого приёма (classify минуется).
    app(HandleQuestion::class)->handle(['message' => ['text' => 'это грудка индейки на гриле']], $this->profile);

    $lunch->refresh();
    expect($lunch->score)->toBe(9)
        ->and($lunch->rating['reevaluated'])->toBeTrue();
    expect($this->profile->fresh()->waiting('reeval'))->toBeNull();

    // Модель звалась промптом ПЕРЕОЦЕНКИ, а не классификатором (classify минуется).
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Переоцени УЖЕ записанный'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Классифицируй сообщение'));
});

it('does not re-evaluate an ordinary question even when a recent meal exists', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 30, 0, 'Europe/Moscow'));
    $lunch = seedEatenMeal($this->profile, 'lunch', 12, 7, 'Хороший обед 👌🏻');

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(reevalMsg([
            'intent' => 'question',
            'reports' => [],
            'reply' => 'Вода без газа — лучший выбор между приёмами 👌🏻',
            'target' => null,
        ])),
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'а что попить между приёмами?']], $this->profile);

    $lunch->refresh();
    // Балл не тронут, флаг переоценки не выставлен.
    expect($lunch->score)->toBe(7)
        ->and($lunch->rating)->not->toHaveKey('reevaluated');
    expect(reevalLastOut())->toContain('лучший выбор между приёмами');
});

it('keeps the prior score and does not leak raw JSON when the re-eval score is out of range', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 10, 0, 'Europe/Moscow'));
    // Обед разобран по фото с осмысленным баллом 2.
    $lunch = seedEatenMeal($this->profile, 'lunch', 12, 2, 'Спорный состав 🤔');

    // Одна фикстура: кнопка (telegram) + переоценка с битым score (anthropic).
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(reevalMsg([
            'feedback' => 'Хороший белковый приём 👌🏻',
            'score' => 99,
            'composition_ok' => true,
            'forbidden' => [],
            'comment' => '',
        ])),
    ]);

    app(HandleCallback::class)->handle(['callback_query' => ['id' => 'cbr', 'data' => 'reeval:'.$lunch->id]], $this->profile);
    app(HandleQuestion::class)->handle(['message' => ['text' => 'это курогрудь']], $this->profile);

    $lunch->refresh();
    // Битый score (99) НЕ обнуляет прежний осмысленный балл — он сохранён.
    expect($lunch->score)->toBe(2)
        // Фидбек и структура состава при этом обновлены (score невалиден отдельно).
        ->and($lunch->ai_feedback)->toBe('Хороший белковый приём 👌🏻')
        ->and($lunch->rating['composition_ok'])->toBeTrue()
        ->and($lunch->rating['reevaluated'])->toBeTrue();

    // Ответ не заявляет новый балл, честно говорит про сохранённый прежний; без JSON-утечки.
    expect(reevalLastOut())
        ->toContain('балл оставил прежним')
        ->toContain('2/10')
        ->not->toContain('"score"')
        ->not->toContain('composition_ok');
});

it('does not wipe the meal and softly refuses when the re-eval model call fails (Claude::text null)', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 10, 0, 'Europe/Moscow'));
    // Обед разобран по фото: осмысленный балл 7 и фидбек.
    $lunch = seedEatenMeal($this->profile, 'lunch', 12, 7, 'Хороший обед, чистый белок 👌🏻');
    $priorRating = $lunch->rating;

    // Anthropic отдаёт пустой content → Claude::text вернёт null (пустой ответ после
    // проверок), моделируя сбой API/пустой ответ после retry. parseFood(null) → всё null.
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => []]),
    ]);

    // Кнопка «переоценить» → ожидание reeval, затем текст-уточнение.
    app(HandleCallback::class)->handle(['callback_query' => ['id' => 'cbr', 'data' => 'reeval:'.$lunch->id]], $this->profile);
    app(HandleQuestion::class)->handle(['message' => ['text' => 'это курогрудь су-вид']], $this->profile);

    $lunch->refresh();
    // Приём НЕ изменён: прежний балл, фидбек и rating на месте, флага reevaluated нет.
    expect($lunch->score)->toBe(7)
        ->and($lunch->ai_feedback)->toBe('Хороший обед, чистый белок 👌🏻')
        ->and($lunch->rating)->toEqual($priorRating)
        ->and($lunch->rating)->not->toHaveKey('reevaluated');

    // Пользователю ушёл мягкий отказ, а не ложное «Пересчитал ✅».
    expect(reevalLastOut())
        ->toContain('не смог сейчас пересчитать')
        ->not->toContain('Пересчитал')
        ->not->toContain('пересчитал Обед');

    // Ожидание reeval ПЕРЕАРМИРОВАНО: следующий текст снова попадёт в переоценку
    // этого же приёма без повторного нажатия кнопки.
    expect($this->profile->fresh()->waiting('reeval'))->toBe($lunch->id);
});

it('keeps the name prefix once and leaves future-intent as a question', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 10, 0, 'Europe/Moscow'));
    $lunch = seedEatenMeal($this->profile, 'lunch', 12, 2, 'Спорный состав 🤔');

    // Одна фикстура: correct_meal + eval + вопрос про будущее.
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::sequence()
            ->push(reevalMsg(['intent' => 'correct_meal', 'reports' => [], 'reply' => '', 'target' => null]))
            ->push(reevalMsg(['feedback' => 'отличный чистый белок, так держать! 🙌🏼', 'score' => 9, 'composition_ok' => true, 'forbidden' => [], 'comment' => '']))
            ->push(reevalMsg(['intent' => 'question', 'reports' => [], 'reply' => 'На ужин лучше белок — рыба или индейка 👌🏻', 'target' => null])),
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'это курогрудь су-вид, а не паштет']], $this->profile);

    $out = reevalLastOut();
    expect($out)->toStartWith('Глеб, пересчитал Обед');
    // Имя ровно один раз.
    expect(substr_count($out, 'Глеб,'))->toBe(1);

    // Инвариант: сообщение о будущем/намерении остаётся вопросом даже при наличии
    // разобранного приёма — correct_meal не триггерится.
    app(HandleQuestion::class)->handle(['message' => ['text' => 'что съесть на ужин попозже?']], $this->profile);

    $lunch->refresh();
    // Обед не переоценивался повторно этим будущим-вопросом.
    expect($lunch->score)->toBe(9);
    expect(reevalLastOut())->toContain('лучше белок');

    // В промпте классификатора есть correct_meal-подсказка (разобранный приём есть).
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com')
        && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'correct_meal'));
});
