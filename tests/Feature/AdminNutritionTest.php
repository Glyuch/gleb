<?php

use App\Models\NutritionInvite;
use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Models\User;
use Carbon\CarbonImmutable;

function nutriSiteAdmin(): User
{
    // is_admin is not mass-assignable; mirror the existing admin-test convention.
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('redirects guests to login', function () {
    $this->get('/admin/nutrition')->assertRedirect('/login');
});

it('forbids non-admins', function () {
    $p = nutritionProfile(); // real profile so route-model binding resolves before the admin gate
    $this->actingAs(User::factory()->create())->get('/admin/nutrition')->assertForbidden();
    $this->actingAs(User::factory()->create())->get("/admin/nutrition/{$p->id}")->assertForbidden();
});

it('lists profiles for an admin', function () {
    nutritionProfile(['name' => 'Глеб']);
    nutritionProfile(['telegram_user_id' => 555, 'name' => 'Аня', 'is_admin' => false, 'username' => 'anya']);

    $this->actingAs(nutriSiteAdmin())->get('/admin/nutrition')
        ->assertOk()
        ->assertSee('Глеб')
        ->assertSee('Аня');
});

it('generates an invite from the admin panel', function () {
    $owner = nutritionProfile();
    expect(NutritionInvite::count())->toBe(0);

    $this->actingAs(nutriSiteAdmin())->post('/admin/nutrition/invite')
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(NutritionInvite::count())->toBe(1);
    $invite = NutritionInvite::first();
    expect($invite->created_by_profile_id)->toBe($owner->id)
        ->and($invite->used_by_profile_id)->toBeNull();
});

it('toggles pause status', function () {
    $p = nutritionProfile(['status' => 'active']);

    $this->actingAs(nutriSiteAdmin())->post("/admin/nutrition/{$p->id}/pause")->assertRedirect();
    expect($p->fresh()->status)->toBe('paused');

    $this->actingAs(nutriSiteAdmin())->post("/admin/nutrition/{$p->id}/pause")->assertRedirect();
    expect($p->fresh()->status)->toBe('active');
});

it('does not pause an onboarding profile', function () {
    $p = nutritionProfile(['status' => 'onboarding']);

    $this->actingAs(nutriSiteAdmin())->post("/admin/nutrition/{$p->id}/pause")->assertRedirect();
    expect($p->fresh()->status)->toBe('onboarding');
});

it('writes settings and ai_profile to the profile', function () {
    $p = nutritionProfile();

    $this->actingAs(nutriSiteAdmin())->put("/admin/nutrition/{$p->id}", [
        'wake_time' => '06:30',
        'sleep_time' => '22:45',
        'steps_target' => 9000,
        'portion_adjustment' => -1,
        'ai_profile' => 'Любит острое.',
    ])->assertRedirect()->assertSessionHas('status');

    $p->refresh();
    expect($p->settings['wake_time'])->toBe('06:30')
        ->and($p->settings['sleep_time'])->toBe('22:45')
        ->and($p->settings['steps_target'])->toBe(9000)
        ->and($p->settings['portion_adjustment'])->toBe(-1)
        ->and($p->ai_profile)->toBe('Любит острое.');
});

it('clears ai_profile when submitted empty', function () {
    $p = nutritionProfile(['ai_profile' => 'старое']);

    $this->actingAs(nutriSiteAdmin())->put("/admin/nutrition/{$p->id}", [
        'wake_time' => '07:00',
        'sleep_time' => '23:00',
        'steps_target' => 7000,
        'portion_adjustment' => 0,
        'ai_profile' => '',
    ])->assertRedirect();

    expect($p->fresh()->ai_profile)->toBeNull();
});

it('validates settings input', function () {
    $p = nutritionProfile();

    $this->actingAs(nutriSiteAdmin())->put("/admin/nutrition/{$p->id}", [
        'wake_time' => 'nope',
        'sleep_time' => '22:45',
        'steps_target' => 100,
        'portion_adjustment' => 9,
    ])->assertSessionHasErrors(['wake_time', 'steps_target', 'portion_adjustment']);
});

it('shows a profile with aggregates', function () {
    $p = nutritionProfile();
    $today = CarbonImmutable::now('Europe/Moscow');

    NutritionMeal::create([
        'profile_id' => $p->id, 'date' => $today->format('Y-m-d'),
        'type' => 'lunch', 'status' => 'eaten', 'score' => 8,
    ]);
    NutritionMetric::create([
        'profile_id' => $p->id, 'date' => $today->subDays(20)->format('Y-m-d'),
        'type' => 'weight', 'value' => 82.0,
    ]);
    NutritionMetric::create([
        'profile_id' => $p->id, 'date' => $today->format('Y-m-d'),
        'type' => 'weight', 'value' => 80.5,
    ]);

    $this->actingAs(nutriSiteAdmin())->get("/admin/nutrition/{$p->id}")
        ->assertOk()
        ->assertSee('средний балл 7д')
        ->assertSee('Динамика веса');
});

it('marks the nutrition tab active in the sidebar', function () {
    nutritionProfile();
    $this->actingAs(nutriSiteAdmin())->get('/admin/nutrition')->assertSee('class="active"', false);
});
