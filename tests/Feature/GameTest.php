<?php

use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Database\Seeders\GameContentSeeder;

beforeEach(function () {
    $this->seed(GameContentSeeder::class);
});

it('redirects guests from the game to login', function () {
    $this->get('/game')->assertRedirect('/login');
});

it('shows the game to an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/game')
        ->assertOk()
        ->assertSee('GAME1')
        ->assertSee($user->name);
});

it('stores a game result with sanitized survey answers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/result', [
        'score_you' => 350000,
        'choices' => [['quarter' => 1, 'k' => 'stock', 'ret' => 0.012]],
        'survey' => [
            'helped' => 'Да',
            'priority' => 'Надёжность',
            'unknown_question' => 'whatever',
            'helped' => 'Да',
        ],
    ])->assertOk()->assertJson(['promo' => 'GAME1']);

    $result = GameResult::first();
    expect($result->user_id)->toBe($user->id);
    expect($result->score_you)->toBe(350000);
    expect($result->promo_code)->toBe('GAME1');
    expect($result->score_bank)->toBeGreaterThan(300000);
    expect($result->survey_answers)->toMatchArray(['helped' => 'Да', 'priority' => 'Надёжность']);
    expect($result->survey_answers)->not->toHaveKey('unknown_question');
    expect($result->choices)->toHaveCount(1);
});

it('rejects an impossible score', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/result', ['score_you' => 99999999])
        ->assertStatus(422);
});

it('ranks the leaderboard by best score per user', function () {
    $alice = User::factory()->create(['name' => 'Алиса']);
    $bob = User::factory()->create(['name' => 'Боб']);

    GameResult::create(['user_id' => $alice->id, 'score_you' => 400000, 'score_bank' => 1, 'score_max' => 1, 'ratio' => 50]);
    GameResult::create(['user_id' => $alice->id, 'score_you' => 300000, 'score_bank' => 1, 'score_max' => 1, 'ratio' => 40]);
    GameResult::create(['user_id' => $bob->id, 'score_you' => 500000, 'score_bank' => 1, 'score_max' => 1, 'ratio' => 60]);

    $data = $this->actingAs($alice)->getJson('/game/leaderboard')->assertOk()->json();

    expect($data['leaderboard'])->toHaveCount(2);
    expect($data['leaderboard'][0]['name'])->toBe('Боб');
    expect($data['leaderboard'][0]['score'])->toBe(500000);
    expect($data['leaderboard'][1]['name'])->toBe('Алиса');
    expect($data['leaderboard'][1]['score'])->toBe(400000);
    expect($data['rank'])->toBe(2);
});

it('logs valid funnel events and rejects unknown ones', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/event', ['event' => 'open'])->assertOk();
    $this->actingAs($user)->postJson('/game/event', ['event' => 'choice', 'payload' => ['quarter' => 1, 'k' => 'bond']])->assertOk();
    $this->actingAs($user)->postJson('/game/event', ['event' => 'bogus'])->assertStatus(422);

    expect(GameEvent::where('event', 'open')->count())->toBe(1);
    expect(GameEvent::where('event', 'choice')->count())->toBe(1);
});
