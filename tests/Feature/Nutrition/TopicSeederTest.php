<?php

use App\Actions\Nutrition\StartProgram;
use App\Models\NutritionTopic;
use App\Models\NutritionTopicSend;
use Carbon\CarbonImmutable;
use Database\Seeders\NutritionTopicSeeder;
use Illuminate\Support\Facades\Http;

it('seeds exactly 12 topics with unique positions 1..12', function () {
    $this->seed(NutritionTopicSeeder::class);

    expect(NutritionTopic::query()->count())->toBe(12);

    $positions = NutritionTopic::query()->orderBy('position')->pluck('position')->all();
    expect($positions)->toBe(range(1, 12));

    $first = NutritionTopic::query()->where('position', 1)->first();
    expect($first->title)->toBe('Про метаболизм')
        ->and($first->file_path)->toBe('Про метаболизм.pdf')
        ->and($first->intro)->not->toBeEmpty();

    // Точное имя файла с пробелом перед .pdf для сборника рецептов + второй файл через «|».
    $book = NutritionTopic::query()->where('position', 11)->first();
    expect($book->file_path)->toBe('Здоровая Еда .pdf|Закупочный_лист_Приложение_к_сборнику_Здоровая_Еда_Андрей_Мокич.pdf');

    // Составные темы хранят несколько файлов через разделитель «|».
    $walking = NutritionTopic::query()->where('position', 4)->first();
    expect($walking->file_path)->toContain('|')
        ->and($walking->file_path)->toBe('ХОДЬБА Файл Марина.pdf|Окно жиросжигания.pdf');
});

it('is idempotent and refreshes title/file_path/intro on re-seed', function () {
    $this->seed(NutritionTopicSeeder::class);

    $topic = NutritionTopic::query()->where('position', 1)->first();
    $topic->update(['title' => 'Изменено вручную']);

    $this->seed(NutritionTopicSeeder::class);

    expect(NutritionTopic::query()->count())->toBe(12);

    // title восстановлен из сидера.
    expect($topic->fresh()->title)->toBe('Про метаболизм');
});

it('start-program command lays out per-profile topic sends: position 1 -> day +3', function () {
    $profile = nutritionProfile();
    $this->seed(NutritionTopicSeeder::class);

    $this->artisan('nutrition:start-program', ['date' => '2026-07-14'])
        ->assertExitCode(0);

    expect($profile->fresh()->program_started_on->format('Y-m-d'))->toBe('2026-07-14')
        ->and($profile->fresh()->phase)->toBe('program');

    $topic1 = NutritionTopic::query()->where('position', 1)->first();
    $topic12 = NutritionTopic::query()->where('position', 12)->first();

    $send1 = NutritionTopicSend::query()->where('profile_id', $profile->id)->where('topic_id', $topic1->id)->first();
    $send12 = NutritionTopicSend::query()->where('profile_id', $profile->id)->where('topic_id', $topic12->id)->first();

    expect(NutritionTopicSend::query()->where('profile_id', $profile->id)->count())->toBe(12)
        ->and($send1->scheduled_on->format('Y-m-d'))->toBe('2026-07-17')   // +3
        ->and($send12->scheduled_on->format('Y-m-d'))->toBe('2026-09-10'); // +58
});

it('recomputes topic-send dates on re-run without resetting sent_at', function () {
    $profile = nutritionProfile();
    $this->seed(NutritionTopicSeeder::class);

    $this->artisan('nutrition:start-program', ['date' => '2026-07-14'])->assertExitCode(0);

    $topic1 = NutritionTopic::query()->where('position', 1)->first();
    $send1 = NutritionTopicSend::query()->where('profile_id', $profile->id)->where('topic_id', $topic1->id)->first();
    // Тема уже была отправлена.
    $send1->update(['sent_at' => '2026-07-17 10:30:00']);

    $this->artisan('nutrition:start-program', ['date' => '2026-07-21'])->assertExitCode(0);

    expect($profile->fresh()->program_started_on->format('Y-m-d'))->toBe('2026-07-21');

    $send1->refresh();
    // scheduled_on пересчитан, а sent_at сохранён.
    expect($send1->scheduled_on->format('Y-m-d'))->toBe('2026-07-24') // 2026-07-21 +3
        ->and($send1->sent_at->format('Y-m-d H:i:s'))->toBe('2026-07-17 10:30:00');
});

it('start-program targets a specific profile via --profile', function () {
    // null — часть сценария: admin программу не стартовал, start-program целится в other.
    $admin = nutritionProfile(['program_started_on' => null]);
    $other = nutritionProfile(['telegram_user_id' => 999, 'is_admin' => false, 'main_chat_id' => 999]);
    $this->seed(NutritionTopicSeeder::class);

    $this->artisan('nutrition:start-program', ['date' => '2026-07-14', '--profile' => $other->id])
        ->assertExitCode(0);

    // Раскладка ушла второму профилю, admin не тронут.
    expect(NutritionTopicSend::query()->where('profile_id', $other->id)->count())->toBe(12)
        ->and(NutritionTopicSend::query()->where('profile_id', $admin->id)->count())->toBe(0)
        ->and($other->fresh()->program_started_on->format('Y-m-d'))->toBe('2026-07-14')
        ->and($admin->fresh()->program_started_on)->toBeNull();
});

it('StartProgram action returns a human-readable summary', function () {
    $profile = nutritionProfile();
    $this->seed(NutritionTopicSeeder::class);

    $summary = app(StartProgram::class)->handle($profile, CarbonImmutable::parse('2026-07-14', 'Europe/Moscow'));

    expect($summary)->toContain('14.07.2026')
        ->and($summary)->toContain('12')
        ->and($summary)->toContain('Про метаболизм');
});

it('set-webhook succeeds when Telegram returns boolean result true', function () {
    config(['nutrition.bot_token' => 'test-token', 'nutrition.webhook_secret' => 'test-secret']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true, 'description' => 'Webhook was set']),
    ]);

    $this->artisan('nutrition:set-webhook')->assertExitCode(0);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/setWebhook')
            && $request['secret_token'] === 'test-secret'
            && $request['allowed_updates'] === json_encode(['message', 'callback_query'])
            && str_contains((string) $request['url'], '/nutrition-bot/webhook');
    });
});

it('set-webhook fails with exit code 1 when the API call errors', function () {
    config(['nutrition.bot_token' => 'test-token', 'nutrition.webhook_secret' => 'test-secret']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
    ]);

    $this->artisan('nutrition:set-webhook')->assertExitCode(1);
});

it('set-webhook fails with exit code 1 without secrets and makes no HTTP calls', function () {
    config(['nutrition.bot_token' => null, 'nutrition.webhook_secret' => null]);

    Http::fake();

    $this->artisan('nutrition:set-webhook')->assertExitCode(1);

    Http::assertNothingSent();
});
