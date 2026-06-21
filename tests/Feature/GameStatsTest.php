<?php

use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Database\Seeders\GameContentSeeder;

beforeEach(function () {
    $this->seed(GameContentSeeder::class);
});

it('renders funnel, choice and survey statistics', function () {
    $admin = makeAdmin();
    $player = User::factory()->create();

    foreach (['open', 'start', 'finish', 'open_fund'] as $event) {
        GameEvent::create(['user_id' => $player->id, 'event' => $event]);
    }
    GameEvent::create(['user_id' => $player->id, 'event' => 'choice', 'payload' => ['quarter' => 1, 'k' => 'stock']]);

    GameResult::create([
        'user_id' => $player->id,
        'score_you' => 400000,
        'score_bank' => 320000,
        'score_max' => 580000,
        'ratio' => 69,
        'choices' => [['quarter' => 1, 'k' => 'stock'], ['quarter' => 2, 'k' => 'bond']],
        'survey_answers' => ['helped' => 'Да', 'priority' => 'Надёжность'],
    ]);

    $this->actingAs($admin)->get('/admin/game/stats')
        ->assertOk()
        ->assertSee('Воронка')
        ->assertSee('Перешли на Финуслуги')
        ->assertSee('Решения по ходам')
        ->assertSee('Кв 1')
        ->assertSee('Вариант / ход')
        ->assertSee('Ответы на опрос')
        ->assertSee('Фонд акций');
});

it('renders the per-user leaderboard ranked by best score with plays and last survey answers', function () {
    $admin = makeAdmin();
    $strong = User::factory()->create(['name' => 'Сильный Игрок', 'email' => 'strong@example.test']);
    $weak = User::factory()->create(['name' => 'Слабый Игрок', 'email' => 'weak@example.test']);

    // Strong player: best game is the FIRST row (500000), latest game is the SECOND row (300000).
    // The leaderboard must rank by best (500000) but show survey answers from the latest attempt.
    GameResult::create([
        'user_id' => $strong->id, 'score_you' => 500000, 'score_bank' => 320000, 'score_max' => 580000, 'ratio' => 86,
        'survey_answers' => ['priority' => 'BEST_ATTEMPT_SENTINEL'],
    ]);
    GameResult::create([
        'user_id' => $strong->id, 'score_you' => 300000, 'score_bank' => 320000, 'score_max' => 580000, 'ratio' => 51,
        'survey_answers' => ['priority' => 'LATEST_ATTEMPT_SENTINEL'],
    ]);
    GameEvent::create(['user_id' => $strong->id, 'event' => 'start']);
    GameEvent::create(['user_id' => $strong->id, 'event' => 'start']);
    GameEvent::create(['user_id' => $strong->id, 'event' => 'start']);

    // Weak player: one completed game, did not beat the bank.
    GameResult::create([
        'user_id' => $weak->id, 'score_you' => 200000, 'score_bank' => 320000, 'score_max' => 580000, 'ratio' => 34,
        'survey_answers' => ['priority' => 'Надёжность'],
    ]);

    $response = $this->actingAs($admin)->get('/admin/game/stats')
        ->assertOk()
        ->assertSee('Игроки · лидерборд и опрос')
        ->assertSee('Сильный Игрок')
        ->assertSee('strong@example.test')
        ->assertSee('Слабый Игрок')
        ->assertSee('500 000')                       // best portfolio of the strong player
        ->assertSee('LATEST_ATTEMPT_SENTINEL');      // survey is taken from the latest attempt

    $body = $response->getContent();

    // Strong player (best 500000) must be ranked above the weak player (best 200000).
    expect(strpos($body, 'Сильный Игрок'))->toBeLessThan(strpos($body, 'Слабый Игрок'));

    // The non-latest attempt's survey answer must NOT be shown — only the latest attempt is used.
    expect($body)->not->toContain('BEST_ATTEMPT_SENTINEL');
});
