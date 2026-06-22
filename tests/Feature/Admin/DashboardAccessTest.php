<?php

use App\Models\GameContent;
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

it('redirects the old stats path and /admin root', function () {
    $admin = admin();
    $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/dashboards/site');
    $this->actingAs($admin)->get('/admin/game/stats')->assertRedirect('/admin/dashboards/gameresults');
});
