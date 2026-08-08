<?php

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;

it('creates the full object graph via factories', function () {
    $manager = ShtabPerson::factory()->create(['is_me' => true]);
    $person = ShtabPerson::factory()->create(['manager_id' => $manager->id]);

    $product = ShtabObject::factory()->create(['type' => 'product', 'focus_level' => 2]);
    $project = ShtabObject::factory()->create(['type' => 'project', 'parent_id' => $product->id]);

    $metric = ShtabMetric::factory()->create(['object_id' => $product->id, 'status' => 'red']);
    $businessMetric = ShtabMetric::factory()->create(['object_id' => null]);

    $assignment = ShtabAssignment::factory()->create([
        'person_id' => $person->id,
        'object_id' => $project->id,
    ]);

    expect($person->manager->is($manager))->toBeTrue()
        ->and($project->parent->is($product))->toBeTrue()
        ->and($product->children->first()->is($project))->toBeTrue()
        ->and($product->metrics->first()->is($metric))->toBeTrue()
        ->and($businessMetric->object_id)->toBeNull()
        ->and($person->activeAssignments->first()->is($assignment))->toBeTrue()
        ->and($project->activeAssignments->first()->person->is($person))->toBeTrue();
});

it('excludes ended assignments from active scopes', function () {
    $assignment = ShtabAssignment::factory()->create(['ended_at' => now()->toDateString()]);

    expect(ShtabAssignment::query()->active()->count())->toBe(0)
        ->and($assignment->person->activeAssignments)->toHaveCount(0)
        ->and($assignment->person->in_reserve ?? true)->toBeTrue();
});

it('records events with payload', function () {
    $person = ShtabPerson::factory()->create();

    $event = ShtabEvent::record('assignment_started', [
        'person_id' => $person->id,
        'payload' => ['role_label' => 'владелец'],
        'comment' => 'тест',
    ]);

    expect($event->refresh()->payload['role_label'])->toBe('владелец')
        ->and($event->type)->toBe('assignment_started')
        ->and($event->person->is($person))->toBeTrue();
});
