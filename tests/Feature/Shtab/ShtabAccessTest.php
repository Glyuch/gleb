<?php

use App\Models\User;

it('redirects guests to login', function () {
    $this->get('/shtab')->assertRedirect('/login');
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create())->get('/shtab')->assertForbidden();
});

it('renders the shtab page for the admin', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get('/shtab')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shtab/index')
            ->has('board.people')
            ->has('board.objects')
            ->has('board.business_metrics')
            ->has('events'));
});
