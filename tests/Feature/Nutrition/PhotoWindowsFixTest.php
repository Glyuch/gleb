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

it('attaches a late photo to a meal marked eaten by button without a photo', function () {
    // Баг из чата: полдник отмечен кнопкой (eaten, без фото) в 18:30, ужин ещё
    // не в окне (19:00). Досланное в 18:36 фото должно прицепиться к полднику,
    // а не отбиться «перекусов нет».
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
        ->and($snack->ai_feedback)->toBe('Идеально! 🙌🏼');
    expect(lastOutContent())->toContain('Глеб, идеально')
        ->and(lastOutContent())->not->toContain('Перекусов');
});

it('still replies no snacks when the photoless eaten meal is older than the fallback window', function () {
    // Полдник отмечен кнопкой давно (17:00) — за пределами 40-мин фолбэка →
    // честное «перекусов нет».
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

it('processes only the first photo of an album (same media_group_id)', function () {
    // Обед активен (11:30). Альбом из двух фото с общим media_group_id → один
    // разбор, второй апдейт молча пропускается (без повторной отбивки).
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
    // Полдник кнопкой в 18:30 → ужин уезжает на 20:00–21:00 (eaten+3ч=21:30,
    // обрезка сном 23:00). Кнопка «✅ Поел» должна сообщить новое окно.
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
    // Пропуск обеда в 11:30 → полдник сдвигается на 15:30–16:30. Кнопка
    // «⏭ Пропускаю» должна сообщить новое окно.
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));
    Planner::ensureDay($this->profile, CarbonImmutable::now('Europe/Moscow'));

    app(HandleCallback::class)->handle(['callback_query' => ['id' => 'cby', 'data' => 'skip:lunch']], $this->profile);

    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('skipped');
    $out = lastOutContent();
    expect($out)->toContain('Обед пропущен ⏭')
        ->and($out)->toContain('Полдник теперь 15:30');
});
