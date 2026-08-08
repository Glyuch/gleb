<?php

use App\Actions\Shtab\BuildShtabBoard;
use App\Models\ShtabAssignment;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;

function board(): array
{
    return (new BuildShtabBoard)->handle();
}

it('marks people without active assignments as reserve', function () {
    $busy = ShtabPerson::factory()->create();
    $idle = ShtabPerson::factory()->create();
    ShtabAssignment::factory()->create(['person_id' => $busy->id]);
    ShtabAssignment::factory()->create(['person_id' => $idle->id, 'ended_at' => now()->toDateString()]);

    $people = collect(board()['people'])->keyBy('id');

    expect($people[$busy->id]['in_reserve'])->toBeFalse()
        ->and($people[$idle->id]['in_reserve'])->toBeTrue();
});

it('counts hot assignments and flags overload above threshold', function () {
    config(['shtab.overload_threshold' => 2]);
    $person = ShtabPerson::factory()->create();
    foreach ([2, 2, 1] as $level) {
        ShtabAssignment::factory()->create([
            'person_id' => $person->id,
            'object_id' => ShtabObject::factory()->create(['focus_level' => $level])->id,
        ]);
    }
    ShtabAssignment::factory()->create([
        'person_id' => $person->id,
        'object_id' => ShtabObject::factory()->create(['focus_level' => 0])->id,
    ]);

    $row = collect(board()['people'])->firstWhere('id', $person->id);

    expect($row['focus_count'])->toBe(4)
        ->and($row['hot_count'])->toBe(3)
        ->and($row['is_overloaded'])->toBeTrue();
});

it('computes uncovered days from the last ended assignment', function () {
    $object = ShtabObject::factory()->create(['focus_level' => 2, 'created_at' => now()->subDays(60)]);
    ShtabAssignment::factory()->create([
        'object_id' => $object->id,
        'started_at' => now()->subDays(40)->toDateString(),
        'ended_at' => now()->subDays(12)->toDateString(),
    ]);

    $row = collect(board()['objects'])->firstWhere('id', $object->id);

    expect($row['is_uncovered'])->toBeTrue()
        ->and($row['uncovered_days'])->toBe(12);
});

it('computes uncovered days from creation when never assigned', function () {
    $object = ShtabObject::factory()->create(['created_at' => now()->subDays(9)]);

    $row = collect(board()['objects'])->firstWhere('id', $object->id);

    expect($row['uncovered_days'])->toBe(9);
});

it('reports days on object for active assignments', function () {
    $assignment = ShtabAssignment::factory()->create(['started_at' => now()->subDays(51)->toDateString()]);

    $row = collect(board()['objects'])->firstWhere('id', $assignment->object_id);

    expect($row['is_uncovered'])->toBeFalse()
        ->and($row['assignments'][0]['days'])->toBe(51);
});

it('orders objects by focus level desc and separates business metrics', function () {
    $background = ShtabObject::factory()->create(['focus_level' => 0]);
    $hot = ShtabObject::factory()->create(['focus_level' => 2]);
    ShtabMetric::factory()->create(['object_id' => null, 'name' => 'выручка']);

    $result = board();

    expect($result['objects'][0]['id'])->toBe($hot->id)
        ->and($result['objects'][1]['id'])->toBe($background->id)
        ->and($result['business_metrics'][0]['name'])->toBe('выручка');
});

it('hides archived people and objects from the board', function () {
    ShtabPerson::factory()->create(['archived_at' => now()]);
    ShtabObject::factory()->create(['archived_at' => now()]);

    expect(board()['people'])->toHaveCount(0)
        ->and(board()['objects'])->toHaveCount(0);
});
