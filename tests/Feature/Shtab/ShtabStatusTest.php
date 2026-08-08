<?php

use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\User;

function statusAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('changes metric status and records old and new values', function () {
    $metric = ShtabMetric::factory()->create(['status' => 'green', 'value_text' => '12%']);

    $this->actingAs(statusAdmin())
        ->patch("/shtab/metrics/{$metric->id}/status", [
            'status' => 'red',
            'value_text' => '8%',
            'comment' => 'просели после релиза',
        ])
        ->assertRedirect();

    expect($metric->refresh()->status)->toBe('red')
        ->and($metric->value_text)->toBe('8%');

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('metric_status_changed')
        ->and($event->metric_id)->toBe($metric->id)
        ->and($event->object_id)->toBe($metric->object_id)
        ->and($event->payload)->toBe(['from' => 'green', 'to' => 'red', 'value_text' => '8%'])
        ->and($event->comment)->toBe('просели после релиза');
});

it('rejects unknown metric status', function () {
    $metric = ShtabMetric::factory()->create();

    $this->actingAs(statusAdmin())
        ->from('/shtab')
        ->patch("/shtab/metrics/{$metric->id}/status", ['status' => 'purple'])
        ->assertSessionHasErrors('status');
});

it('writes no event when the posted metric status and value are unchanged', function () {
    $metric = ShtabMetric::factory()->create(['status' => 'green', 'value_text' => '12%']);

    $this->actingAs(statusAdmin())
        ->patch("/shtab/metrics/{$metric->id}/status", ['status' => 'green', 'value_text' => '12%'])
        ->assertRedirect();

    expect(ShtabEvent::count())->toBe(0)
        ->and($metric->refresh()->status)->toBe('green')
        ->and($metric->value_text)->toBe('12%');
});

it('changes object focus level and records the change', function () {
    $object = ShtabObject::factory()->create(['focus_level' => 0]);

    $this->actingAs(statusAdmin())
        ->patch("/shtab/objects/{$object->id}/focus", ['focus_level' => 2])
        ->assertRedirect();

    expect($object->refresh()->focus_level)->toBe(2);

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('focus_level_changed')
        ->and($event->object_id)->toBe($object->id)
        ->and($event->payload)->toBe(['from' => 0, 'to' => 2]);
});

it('writes no event when the posted focus level is unchanged', function () {
    $object = ShtabObject::factory()->create(['focus_level' => 1]);

    $this->actingAs(statusAdmin())
        ->patch("/shtab/objects/{$object->id}/focus", ['focus_level' => 1])
        ->assertRedirect();

    expect(ShtabEvent::count())->toBe(0)
        ->and($object->refresh()->focus_level)->toBe(1);
});

it('clears the metric value when posting the same status with a null value', function () {
    $metric = ShtabMetric::factory()->create(['status' => 'green', 'value_text' => '12%']);

    $this->actingAs(statusAdmin())
        ->patch("/shtab/metrics/{$metric->id}/status", ['status' => 'green', 'value_text' => null])
        ->assertRedirect();

    expect($metric->refresh()->status)->toBe('green')
        ->and($metric->value_text)->toBeNull();

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('metric_status_changed')
        ->and($event->payload)->toBe(['from' => 'green', 'to' => 'green', 'value_text' => null]);
});
