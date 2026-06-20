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
        ->assertSee('Ответы на опрос')
        ->assertSee('Фонд акций');
});
