<?php

use App\Models\GameContent;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Database\Seeders\GameContentSeeder;

beforeEach(function () {
    $this->seed(GameContentSeeder::class);
});

/** Build a full, valid choices payload: one instrument for every quarter. */
function allChoices(string $k = 'stock'): array
{
    $n = count(GameContent::current()->data['years']);
    $out = [];
    for ($q = 1; $q <= $n; $q++) {
        $out[] = ['quarter' => $q, 'k' => $k];
    }

    return $out;
}

/** Independent closed-form portfolio for "all contributions into one instrument". */
function closedForm(string $k): int
{
    $years = GameContent::current()->data['years'];
    $n = count($years);
    $c = (int) config('game.contribution');
    $sum = 0.0;
    for ($q = 0; $q < $n; $q++) {
        $f = 1.0;
        for ($t = $q + 1; $t < $n; $t++) {
            $f *= 1 + ($years[$t]['ret'][$k] ?? 0);
        }
        $sum += $c * $f;
    }

    return (int) round($sum);
}

/** Perfect-foresight max: each contribution takes the best instrument for its own remaining path. */
function maxClosed(): int
{
    $years = GameContent::current()->data['years'];
    $n = count($years);
    $c = (int) config('game.contribution');
    $sum = 0.0;
    for ($q = 0; $q < $n; $q++) {
        $best = 0.0;
        foreach (['bank', 'cash', 'bond', 'stock', 'mix'] as $k) {
            $f = 1.0;
            for ($t = $q + 1; $t < $n; $t++) {
                $f *= 1 + ($years[$t]['ret'][$k] ?? 0);
            }
            if ($f > $best) {
                $best = $f;
            }
        }
        $sum += $c * $best;
    }

    return (int) round($sum);
}

it('redirects guests from the game to the branded register page', function () {
    $this->get('/game')->assertRedirect(route('game.register'));
});

it('shows the game to an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/game')
        ->assertOk()
        ->assertSee('GAME1')
        ->assertSee($user->name);
});

it('recomputes the portfolio server-side from choices and stores the composition', function () {
    $user = User::factory()->create();
    $expected = closedForm('stock');

    $this->actingAs($user)->postJson('/game/result', [
        'score_you' => 999, // client value is only a sanity check; server recomputes
        'choices' => allChoices('stock'),
        'survey' => [
            'helped' => 'Да',
            'priority' => 'Надёжность',
            'unknown_question' => 'whatever',
        ],
    ])->assertOk()->assertJson(['promo' => 'GAME1']);

    $result = GameResult::first();
    expect($result->user_id)->toBe($user->id);
    expect($result->game_content_id)->toBe(GameContent::current()->id);
    expect($result->score_you)->toBe($expected);                 // server value, not the client 999
    expect($result->score_you)->toBeLessThanOrEqual($result->score_max);
    expect($result->score_bank)->toBe(closedForm('bank'));        // deposit benchmark pinned independently
    expect($result->score_max)->toBe(maxClosed());                // per-contribution perfect foresight
    expect($result->promo_code)->toBe('GAME1');
    expect($result->choices)->toHaveCount(count(GameContent::current()->data['years']));
    expect($result->composition)->toHaveKeys(['bank', 'cash', 'bond', 'stock', 'mix']);
    expect($result->composition['stock']['share'])->toEqual(1.0); // all-stock → 100% stock (loose: JSON cast may store 1)
    expect($result->survey_answers)->toMatchArray(['helped' => 'Да', 'priority' => 'Надёжность']);
    expect($result->survey_answers)->not->toHaveKey('unknown_question');
});

it('keeps the last contribution intact under q+1 timing (off-by-one guard)', function () {
    $user = User::factory()->create();
    $c = (int) config('game.contribution');

    $this->actingAs($user)->postJson('/game/result', ['choices' => allChoices('bank')])->assertOk();

    $result = GameResult::first();
    // All-in-bank equals the deposit benchmark; and every all-in run keeps at least the
    // final (never-grown) contribution, so you >= one contribution.
    expect($result->score_you)->toBe(closedForm('bank'));
    expect($result->score_you)->toBeGreaterThanOrEqual($c);
});

it('rejects an incomplete choices payload', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/result', [
        'choices' => [['quarter' => 1, 'k' => 'stock']],
    ])->assertStatus(422);

    expect(GameResult::count())->toBe(0);
});

it('rejects choices with an unknown instrument', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/game/result', [
        'choices' => allChoices('gold'),
    ])->assertStatus(422);

    expect(GameResult::count())->toBe(0);
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
    $this->actingAs($user)->postJson('/game/event', ['event' => 'choice', 'payload' => ['quarter' => 1, 'k' => 'mix']])->assertOk();
    $this->actingAs($user)->postJson('/game/event', ['event' => 'bogus'])->assertStatus(422);

    expect(GameEvent::where('event', 'open')->count())->toBe(1);
    expect(GameEvent::where('event', 'choice')->count())->toBe(1);
});
