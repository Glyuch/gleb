<?php

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\User;

function shtabAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('creates an assignment and writes a started event', function () {
    $person = ShtabPerson::factory()->create();
    $object = ShtabObject::factory()->create();

    $this->actingAs(shtabAdmin())
        ->post('/shtab/assignments', [
            'person_id' => $person->id,
            'object_id' => $object->id,
            'role_label' => 'аналитика',
            'role_type' => 'helper',
            'comment' => 'на месяц, до релиза',
        ])
        ->assertRedirect();

    $assignment = ShtabAssignment::sole();
    expect($assignment->started_at->toDateString())->toBe(now()->toDateString())
        ->and($assignment->ended_at)->toBeNull()
        ->and($assignment->role_type)->toBe('helper')
        ->and($assignment->load_percent)->toBe(25);

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('assignment_started')
        ->and($event->person_id)->toBe($person->id)
        ->and($event->object_id)->toBe($object->id)
        ->and($event->payload['role_label'])->toBe('аналитика')
        ->and($event->payload['role_type'])->toBe('helper')
        ->and($event->payload['load_percent'])->toBe(25)
        ->and($event->comment)->toBe('на месяц, до релиза');
});

it('rejects a duplicate active assignment for the same person and object', function () {
    $existing = ShtabAssignment::factory()->create();

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->post('/shtab/assignments', [
            'person_id' => $existing->person_id,
            'object_id' => $existing->object_id,
            'role_label' => 'дубль',
            'role_type' => 'owner',
        ])
        ->assertRedirect('/shtab')
        ->assertSessionHasErrors('person_id');

    expect(ShtabAssignment::count())->toBe(1);
});

it('ends an assignment and writes an ended event', function () {
    $assignment = ShtabAssignment::factory()->create(['started_at' => now()->subDays(10)->toDateString()]);

    $this->actingAs(shtabAdmin())
        ->post("/shtab/assignments/{$assignment->id}/end", ['comment' => 'релиз вышел'])
        ->assertRedirect();

    expect($assignment->refresh()->ended_at->toDateString())->toBe(now()->toDateString());

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('assignment_ended')
        ->and($event->payload['role_label'])->toBe($assignment->role_label)
        ->and($event->payload['days'])->toBe(10)
        ->and($event->comment)->toBe('релиз вышел');
});

it('cannot end an already ended assignment', function () {
    $assignment = ShtabAssignment::factory()->create(['ended_at' => now()->toDateString()]);

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->post("/shtab/assignments/{$assignment->id}/end")
        ->assertSessionHasErrors('assignment');
});

it('moves an assignment: ends the old one and starts a new one atomically', function () {
    $assignment = ShtabAssignment::factory()->create();
    $target = ShtabObject::factory()->create();

    $this->actingAs(shtabAdmin())
        ->post("/shtab/assignments/{$assignment->id}/move", [
            'object_id' => $target->id,
            'role_label' => 'ведёт',
            'role_type' => 'lead',
            'load_percent' => 80,
            'comment' => 'перекинул на запуск',
        ])
        ->assertRedirect();

    expect($assignment->refresh()->ended_at)->not->toBeNull();

    $new = ShtabAssignment::query()->active()->sole();
    expect($new->object_id)->toBe($target->id)
        ->and($new->person_id)->toBe($assignment->person_id)
        ->and($new->role_type)->toBe('lead')
        ->and($new->load_percent)->toBe(80)
        ->and(ShtabEvent::query()->pluck('type')->all())
        ->toBe(['assignment_ended', 'assignment_started']);
});

it('rejects moving onto an object where the person is already active', function () {
    $assignment = ShtabAssignment::factory()->create();
    $target = ShtabObject::factory()->create();
    ShtabAssignment::factory()->create(['person_id' => $assignment->person_id, 'object_id' => $target->id]);

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->post("/shtab/assignments/{$assignment->id}/move", ['object_id' => $target->id, 'role_label' => 'дубль', 'role_type' => 'owner'])
        ->assertSessionHasErrors('object_id');

    expect($assignment->refresh()->ended_at)->toBeNull();
});

it('fills the role label and load from the role type when they are omitted', function () {
    $person = ShtabPerson::factory()->create();
    $object = ShtabObject::factory()->create();

    $this->actingAs(shtabAdmin())
        ->post('/shtab/assignments', ['person_id' => $person->id, 'object_id' => $object->id, 'role_type' => 'owner'])
        ->assertRedirect();

    $assignment = ShtabAssignment::sole();
    expect($assignment->role_label)->toBe('Владелец')
        ->and($assignment->load_percent)->toBe(50);
});

it('updates role type and load without ending the assignment', function () {
    $assignment = ShtabAssignment::factory()->create(['role_type' => 'owner', 'load_percent' => 50]);

    $this->actingAs(shtabAdmin())
        ->patch("/shtab/assignments/{$assignment->id}", ['role_type' => 'watcher', 'load_percent' => 10, 'comment' => 'передал владение'])
        ->assertRedirect();

    expect($assignment->refresh()->ended_at)->toBeNull()
        ->and($assignment->role_type)->toBe('watcher')
        ->and($assignment->load_percent)->toBe(10);

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('assignment_role_changed')
        ->and($event->payload['from']['role_type'])->toBe('owner')
        ->and($event->payload['to']['load_percent'])->toBe(10)
        ->and($event->comment)->toBe('передал владение');
});

it('rejects an unknown role type and an out-of-range load', function () {
    $assignment = ShtabAssignment::factory()->create();

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->patch("/shtab/assignments/{$assignment->id}", ['role_type' => 'царь', 'load_percent' => 500])
        ->assertSessionHasErrors(['role_type', 'load_percent']);
});

it('cannot change the role of an ended assignment', function () {
    $assignment = ShtabAssignment::factory()->create(['ended_at' => now()->toDateString()]);

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->patch("/shtab/assignments/{$assignment->id}", ['role_type' => 'helper'])
        ->assertSessionHasErrors('assignment');
});

it('forbids non-admins from all assignment routes', function () {
    $assignment = ShtabAssignment::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/shtab/assignments', [])->assertForbidden();
    $this->actingAs($user)->post("/shtab/assignments/{$assignment->id}/end")->assertForbidden();
    $this->actingAs($user)->post("/shtab/assignments/{$assignment->id}/move", [])->assertForbidden();
});
