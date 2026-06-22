<?php

use App\Models\GameContent;
use App\Models\GameResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    GameContent::create(['is_active' => true, 'data' => ['years' => [], 'choices' => [], 'survey' => []]]);
});

function admin(): User
{
    // is_admin is not mass-assignable; match the existing test convention (AdminGameContentTest).
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('redirects guests to login', function () {
    $this->get('/admin/dashboards/gameresults')->assertRedirect('/login');
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create()) // verified, non-admin by default
        ->get('/admin/dashboards/gameresults')->assertForbidden();
});

it('serves both dashboards to admins', function () {
    $this->actingAs(admin())->get('/admin/dashboards/gameresults')->assertOk();
    $this->actingAs(admin())->get('/admin/dashboards/site')->assertOk();
});

it('marks the current dashboard active in the sidebar', function () {
    // routeIs() needs real route context (works over HTTP, not in a bare ->render()).
    $this->actingAs(admin())->get('/admin/dashboards/gameresults')->assertSee('class="active"', false);
    $this->actingAs(admin())->get('/admin/dashboards/site')->assertSee('class="active"', false);
});

it('redirects the old stats path and /admin root', function () {
    $admin = admin();
    $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/dashboards/site');
    $this->actingAs($admin)->get('/admin/game/stats')->assertRedirect('/admin/dashboards/gameresults');
});

it('escapes player identity in the rendered dashboard (no stored XSS)', function () {
    $victim = User::factory()->create(['name' => '<script>alert(1)</script>']);
    GameResult::create(['user_id' => $victim->id, 'score_you' => 100, 'score_bank' => 1, 'score_max' => 1, 'ratio' => 1, 'choices' => [], 'survey_answers' => []]);

    $html = $this->actingAs(admin())->get('/admin/dashboards/gameresults')->assertOk()->getContent();

    // The leaderboard is built with innerHTML, so the client must escape name/email.
    expect($html)->toContain('esc(r.name)')->toContain('esc(r.email)');
    // The conditions table also injects admin-controlled scenario strings (instrument
    // labels, quarter titles) into innerHTML — those must be escaped too.
    expect($html)->toContain('esc(LAB[k])')->toContain('esc(c.title');
    // The raw executable tag must never appear unescaped in the served document:
    // @json hex-encodes it inside D, and esc() encodes it again when the row is built.
    expect($html)->not->toContain('<script>alert(1)</script>');
});
