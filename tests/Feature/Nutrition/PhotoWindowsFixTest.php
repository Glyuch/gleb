<?php

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandlePhoto;
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
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
        'api.telegram.org/file/*' => Http::response('BINARYIMAGE'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Идеально! 🙌🏼']]]),
    ]);
});

function lastOutContent(): ?string
{
    return NutritionMessage::query()->where('direction', 'out')->orderByDesc('id')->value('content');
}

it('attaches a late photo to a button-marked meal and keeps its eaten_at', function () {
    // Полдник отмечен кнопкой (eaten, без фото) в 18:30, ужин ещё не в окне.
    // Досланное в 18:36 фото цепляется к полднику; время приёма НЕ съезжает.
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 18, 36, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);
    NutritionMeal::query()->where('type', 'lunch')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 12:00:00']);
    NutritionMeal::query()->where('type', 'snack')->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-13 18:30:00',
        'photo_file_id' => null,
    ]);

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    $snack = NutritionMeal::query()->where('type', 'snack')->first();
    expect($snack->status)->toBe('eaten')
        ->and($snack->photo_file_id)->toBe('big')
        ->and($snack->ai_feedback)->toBe('Идеально! 🙌🏼')
        ->and($snack->eaten_at->format('H:i'))->toBe('18:30'); // НЕ 18:36
    expect(lastOutContent())->toContain('Глеб, идеально')
        ->and(lastOutContent())->not->toContain('Перекусов');
});

it('still replies no snacks when the photoless eaten meal is older than the 40-min silent window', function () {
    // Полдник кнопкой давно (17:00), живых кандидатов нет → «перекусов нет».
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 18, 36, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);
    NutritionMeal::query()->where('type', 'lunch')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 12:00:00']);
    NutritionMeal::query()->where('type', 'snack')->update([
        'status' => 'eaten',
        'eaten_at' => '2026-07-13 17:00:00',
        'photo_file_id' => null,
    ]);

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    expect(lastOutContent())->toContain('Перекусов');
});

it('asks which meal when a photo arrives during an open window and a button-marked meal is still photoless', function () {
    // Кейс из чата (20:01): ужин в окне (20:00–21:00), полдник закрыт кнопкой без
    // фото в 18:30 (91 мин назад, в пределах 2 ч) → фото неоднозначно → спрашиваем.
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 20, 1, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);
    NutritionMeal::query()->where('type', 'lunch')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 12:00:00']);
    NutritionMeal::query()->where('type', 'snack')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 18:30:00', 'photo_file_id' => null]);
    NutritionMeal::query()->where('type', 'dinner')->update(['status' => 'pending', 'window_start' => '2026-07-13 20:00:00', 'window_end' => '2026-07-13 21:00:00']);

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    // Ничего не отмечено, фото отложено, спрошены оба приёма.
    expect(NutritionMeal::query()->where('type', 'dinner')->value('status'))->toBe('pending');
    expect(NutritionMeal::query()->where('type', 'snack')->value('photo_file_id'))->toBeNull();
    expect($this->profile->fresh()->waiting('meal_photo'))->toBe('big');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains((string) ($r['reply_markup'] ?? ''), 'mealphoto:dinner')
        && str_contains((string) ($r['reply_markup'] ?? ''), 'mealphoto:snack'));
});

it('attaches the picked meal photo without moving eaten_at and without touching the other meal', function () {
    // Продолжение: выбрали «Полдник». Фото → полдник, eaten_at остаётся 18:30,
    // ужин не тронут (иначе eaten_at 20:02 сдвинул бы окно ужина).
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 20, 2, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'snack')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 18:30:00', 'photo_file_id' => null]);
    NutritionMeal::query()->where('type', 'dinner')->update(['status' => 'pending', 'window_start' => '2026-07-13 20:00:00', 'window_end' => '2026-07-13 21:00:00']);
    $this->profile->setWaiting('meal_photo', 'big');

    app(HandleCallback::class)->handle(['callback_query' => ['id' => 'cbz', 'data' => 'mealphoto:snack']], $this->profile);

    $snack = NutritionMeal::query()->where('type', 'snack')->first();
    expect($snack->status)->toBe('eaten')
        ->and($snack->photo_file_id)->toBe('big')
        ->and($snack->eaten_at->format('H:i'))->toBe('18:30'); // НЕ 20:02
    expect(NutritionMeal::query()->where('type', 'dinner')->value('status'))->toBe('pending');
    expect($this->profile->fresh()->waiting('meal_photo'))->toBeNull();
});

it('attaches directly to the open meal when the button-marked photoless meal is older than 2h', function () {
    // Полдник кнопкой без фото давно (18:30, 140 мин до 20:50, >2ч) → не предлагаем
    // его, фото уходит в открытый ужин напрямую.
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 20, 50, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);
    NutritionMeal::query()->where('type', 'lunch')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 12:00:00']);
    NutritionMeal::query()->where('type', 'snack')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 18:30:00', 'photo_file_id' => null]);
    NutritionMeal::query()->where('type', 'dinner')->update(['status' => 'pending', 'window_start' => '2026-07-13 20:00:00', 'window_end' => '2026-07-13 21:00:00']);

    app(HandlePhoto::class)->handle(['message' => [
        'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
    ]], $this->profile);

    $dinner = NutritionMeal::query()->where('type', 'dinner')->first();
    expect($dinner->status)->toBe('eaten')
        ->and($dinner->photo_file_id)->toBe('big');
    // Полдник не подхватил фото (его не предлагали).
    expect(NutritionMeal::query()->where('type', 'snack')->value('photo_file_id'))->toBeNull();
});

it('processes only the first photo of an album (same media_group_id)', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);

    app(HandlePhoto::class)->handle(['message' => [
        'media_group_id' => 'g1',
        'photo' => [['file_id' => 'a1'], ['file_id' => 'b1']],
    ]], $this->profile);

    $countAfterFirst = NutritionMessage::query()->where('direction', 'out')->count();

    app(HandlePhoto::class)->handle(['message' => [
        'media_group_id' => 'g1',
        'photo' => [['file_id' => 'a2'], ['file_id' => 'b2']],
    ]], $this->profile->fresh());

    $lunch = NutritionMeal::query()->where('type', 'lunch')->first();
    expect($lunch->status)->toBe('eaten')
        ->and($lunch->photo_file_id)->toBe('b1');
    expect(NutritionMessage::query()->where('direction', 'out')->count())->toBe($countAfterFirst);
});

it('announces the shifted dinner window when a meal is marked eaten by button', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 18, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));
    NutritionMeal::query()->where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 08:00:00']);
    NutritionMeal::query()->where('type', 'lunch')->update(['status' => 'eaten', 'eaten_at' => '2026-07-13 12:00:00']);

    app(HandleCallback::class)->handle(['callback_query' => ['id' => 'cbx', 'data' => 'ate:snack']], $this->profile);

    expect(NutritionMeal::query()->where('type', 'snack')->value('status'))->toBe('eaten');
    $out = lastOutContent();
    expect($out)->toContain('Полдник отмечен ✅')
        ->and($out)->toContain('Ужин теперь 20:00');
});

it('announces the shifted snack window when a meal is skipped by button', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => ['id' => 'cby', 'data' => 'skip:lunch']], $this->profile);

    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('skipped');
    $out = lastOutContent();
    expect($out)->toContain('Обед пропущен ⏭')
        ->and($out)->toContain('Полдник теперь 15:30');
});
