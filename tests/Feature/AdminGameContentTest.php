<?php

use App\Models\GameContent;
use App\Models\User;
use Database\Seeders\GameContentSeeder;

beforeEach(function () {
    $this->seed(GameContentSeeder::class);
});

function makeAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('forbids non-admins from every admin tab', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/game')->assertForbidden();
    $this->actingAs($user)->get('/admin/game/survey')->assertForbidden();
    $this->actingAs($user)->get('/admin/game/stats')->assertForbidden();
});

it('lets an admin open the content editor', function () {
    $this->actingAs(makeAdmin())->get('/admin/game')
        ->assertOk()
        ->assertSee('years');
});

it('saves valid content as a new active version and preserves the survey', function () {
    $admin = makeAdmin();
    $before = GameContent::current();
    $surveyBefore = $before->data['survey'];

    $data = $before->data;
    unset($data['survey']);
    $data['start']['kicker'] = 'ИЗМЕНЕНО';

    $this->actingAs($admin)->put('/admin/game', [
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ])->assertRedirect();

    $after = GameContent::current();
    expect($after->id)->not->toBe($before->id);
    expect($after->data['start']['kicker'])->toBe('ИЗМЕНЕНО');
    expect($after->data['survey'])->toEqual($surveyBefore);
    expect(GameContent::where('is_active', true)->count())->toBe(1);
});

it('rejects invalid json and keeps the active version', function () {
    $admin = makeAdmin();
    $before = GameContent::current();

    $this->actingAs($admin)->put('/admin/game', ['data' => '{not valid'])
        ->assertSessionHasErrors('data');

    expect(GameContent::current()->id)->toBe($before->id);
});

it('rejects content missing required keys', function () {
    $admin = makeAdmin();
    $before = GameContent::current();

    $this->actingAs($admin)->put('/admin/game', ['data' => json_encode(['start' => []])])
        ->assertSessionHasErrors('data');

    expect(GameContent::current()->id)->toBe($before->id);
});
