<?php

use App\Models\GameResult;
use App\Models\User;
use Database\Seeders\GameContentSeeder;

beforeEach(function () {
    $this->seed(GameContentSeeder::class);
});

function adminUser(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('redirects the retired stats page to the game dashboard', function () {
    $this->actingAs(adminUser())->get('/admin/game/stats')
        ->assertRedirect('/admin/dashboards/gameresults');
});

it('renders the game dashboard with the per-user leaderboard for an admin', function () {
    $strong = User::factory()->create(['name' => 'Сильный Игрок', 'email' => 'strong@example.test']);
    $weak = User::factory()->create(['name' => 'Слабый Игрок', 'email' => 'weak@example.test']);

    // Strong player's best game is 500000; the leaderboard ranks by best score per user.
    GameResult::create(['user_id' => $strong->id, 'score_you' => 500000, 'score_bank' => 320000, 'score_max' => 580000, 'ratio' => 86, 'choices' => [], 'survey_answers' => []]);
    GameResult::create(['user_id' => $strong->id, 'score_you' => 300000, 'score_bank' => 320000, 'score_max' => 580000, 'ratio' => 51, 'choices' => [], 'survey_answers' => []]);
    GameResult::create(['user_id' => $weak->id, 'score_you' => 200000, 'score_bank' => 320000, 'score_max' => 580000, 'ratio' => 34, 'choices' => [], 'survey_answers' => []]);

    $body = $this->actingAs(adminUser())->get('/admin/dashboards/gameresults')
        ->assertOk()
        ->assertSee('Игроки')               // merged leaderboard section heading (server HTML)
        ->assertSee('strong@example.test')  // emails are embedded in the leaderboard JSON payload
        ->assertSee('weak@example.test')
        ->getContent();

    // Leaderboard is ranked by best score: the strong player (500000) appears before the weak one (200000).
    expect(strpos($body, 'strong@example.test'))->toBeLessThan(strpos($body, 'weak@example.test'));
});
