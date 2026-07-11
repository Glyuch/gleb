<?php

use App\Actions\Nutrition\StartProgram;
use App\Models\NutritionTopic;
use App\Support\Nutrition\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\NutritionTopicSeeder;

it('seeds exactly 12 topics with unique positions 1..12', function () {
    $this->seed(NutritionTopicSeeder::class);

    expect(NutritionTopic::query()->count())->toBe(12);

    $positions = NutritionTopic::query()->orderBy('position')->pluck('position')->all();
    expect($positions)->toBe(range(1, 12));

    $first = NutritionTopic::query()->where('position', 1)->first();
    expect($first->title)->toBe('Про метаболизм')
        ->and($first->file_path)->toBe('Про метаболизм.pdf')
        ->and($first->intro)->not->toBeEmpty();

    // Точное имя файла с пробелом перед .pdf для сборника рецептов.
    $book = NutritionTopic::query()->where('position', 11)->first();
    expect($book->file_path)->toBe('Здоровая Еда .pdf');
});

it('is idempotent and refreshes title/file_path/intro without touching scheduled_on/sent_at', function () {
    $this->seed(NutritionTopicSeeder::class);

    // Симулируем «раскладку» дат и отправку темы.
    $topic = NutritionTopic::query()->where('position', 1)->first();
    $topic->update([
        'scheduled_on' => '2026-08-01',
        'sent_at' => '2026-08-01 10:30:00',
        'title' => 'Изменено вручную',
    ]);

    $this->seed(NutritionTopicSeeder::class);

    expect(NutritionTopic::query()->count())->toBe(12);

    $topic->refresh();
    // title восстановлен из сидера…
    expect($topic->title)->toBe('Про метаболизм')
        // …а scheduled_on / sent_at остались нетронутыми.
        ->and($topic->scheduled_on->format('Y-m-d'))->toBe('2026-08-01')
        ->and($topic->sent_at->format('Y-m-d H:i:s'))->toBe('2026-08-01 10:30:00');
});

it('start-program command lays out topic dates: position 1 → day +3', function () {
    $this->seed(NutritionTopicSeeder::class);

    $this->artisan('nutrition:start-program', ['date' => '2026-07-14'])
        ->assertExitCode(0);

    expect((string) Settings::get('program_started_on'))->toBe('2026-07-14')
        ->and((string) Settings::get('phase'))->toBe('program');

    $topic1 = NutritionTopic::query()->where('position', 1)->first();
    $topic12 = NutritionTopic::query()->where('position', 12)->first();

    expect($topic1->scheduled_on->format('Y-m-d'))->toBe('2026-07-17')  // +3
        ->and($topic12->scheduled_on->format('Y-m-d'))->toBe('2026-09-10'); // +58
});

it('recomputes dates on re-run of start-program', function () {
    $this->seed(NutritionTopicSeeder::class);

    $this->artisan('nutrition:start-program', ['date' => '2026-07-14'])->assertExitCode(0);
    $this->artisan('nutrition:start-program', ['date' => '2026-07-21'])->assertExitCode(0);

    expect((string) Settings::get('program_started_on'))->toBe('2026-07-21');

    $topic1 = NutritionTopic::query()->where('position', 1)->first();
    expect($topic1->scheduled_on->format('Y-m-d'))->toBe('2026-07-24'); // 2026-07-21 +3
});

it('StartProgram action returns a human-readable summary', function () {
    $this->seed(NutritionTopicSeeder::class);

    $summary = app(StartProgram::class)->handle(CarbonImmutable::parse('2026-07-14', 'Europe/Moscow'));

    expect($summary)->toContain('14.07.2026')
        ->and($summary)->toContain('12')
        ->and($summary)->toContain('Про метаболизм');
});
