<?php

use App\Actions\Shtab\BuildShtabBoard;
use App\Models\ShtabEvent;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\ShtabTask;
use App\Models\User;

function tasksAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('creates a task without events when no assignee is set', function () {
    $object = ShtabObject::factory()->create();

    $this->actingAs(tasksAdmin())
        ->post('/shtab/tasks', ['object_id' => $object->id, 'title' => 'Написать лендинг'])
        ->assertRedirect();

    $task = ShtabTask::sole();
    expect($task->title)->toBe('Написать лендинг')
        ->and($task->object_id)->toBe($object->id)
        ->and($task->is_done)->toBeFalse()
        ->and($task->assignee_person_id)->toBeNull()
        ->and(ShtabEvent::count())->toBe(0);
});

it('creates a task with assignee and writes a task_assigned event', function () {
    $object = ShtabObject::factory()->create();
    $person = ShtabPerson::factory()->create();

    $this->actingAs(tasksAdmin())
        ->post('/shtab/tasks', [
            'object_id' => $object->id,
            'title' => 'Согласовать тарифы',
            'assignee_person_id' => $person->id,
        ])
        ->assertRedirect();

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('task_assigned')
        ->and($event->person_id)->toBe($person->id)
        ->and($event->object_id)->toBe($object->id)
        ->and($event->payload)->toBe(['title' => 'Согласовать тарифы']);
});

it('rejects a task on an archived object', function () {
    $object = ShtabObject::factory()->create(['archived_at' => now()]);

    $this->actingAs(tasksAdmin())
        ->from('/shtab')
        ->post('/shtab/tasks', ['object_id' => $object->id, 'title' => 'не должно создаться'])
        ->assertSessionHasErrors('object_id');

    expect(ShtabTask::count())->toBe(0);
});

it('marks a task done with done_at and a task_done event', function () {
    $person = ShtabPerson::factory()->create();
    $task = ShtabTask::factory()->create(['assignee_person_id' => $person->id]);

    $this->actingAs(tasksAdmin())
        ->patch("/shtab/tasks/{$task->id}", ['is_done' => true])
        ->assertRedirect();

    expect($task->refresh()->is_done)->toBeTrue()
        ->and($task->done_at)->not->toBeNull();

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('task_done')
        ->and($event->object_id)->toBe($task->object_id)
        ->and($event->person_id)->toBe($person->id)
        ->and($event->payload)->toBe(['title' => $task->title]);
});

it('does not write another event when a done task is marked done again', function () {
    $task = ShtabTask::factory()->create(['is_done' => true, 'done_at' => now()]);

    $this->actingAs(tasksAdmin())
        ->patch("/shtab/tasks/{$task->id}", ['is_done' => true])
        ->assertRedirect();

    expect($task->refresh()->is_done)->toBeTrue()
        ->and(ShtabEvent::count())->toBe(0);
});

it('clears done_at on un-done without events', function () {
    $task = ShtabTask::factory()->create(['is_done' => true, 'done_at' => now()]);

    $this->actingAs(tasksAdmin())
        ->patch("/shtab/tasks/{$task->id}", ['is_done' => false])
        ->assertRedirect();

    expect($task->refresh()->is_done)->toBeFalse()
        ->and($task->done_at)->toBeNull()
        ->and(ShtabEvent::count())->toBe(0);
});

it('writes task_assigned only when the assignee changes to a new person', function () {
    $task = ShtabTask::factory()->create();
    $person = ShtabPerson::factory()->create();

    $this->actingAs(tasksAdmin())
        ->patch("/shtab/tasks/{$task->id}", ['assignee_person_id' => $person->id])
        ->assertRedirect();

    expect($task->refresh()->assignee_person_id)->toBe($person->id);

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('task_assigned')
        ->and($event->person_id)->toBe($person->id);

    $this->actingAs(tasksAdmin())
        ->patch("/shtab/tasks/{$task->id}", ['assignee_person_id' => $person->id])
        ->assertRedirect();

    $this->actingAs(tasksAdmin())
        ->patch("/shtab/tasks/{$task->id}", ['assignee_person_id' => null])
        ->assertRedirect();

    expect($task->refresh()->assignee_person_id)->toBeNull()
        ->and(ShtabEvent::count())->toBe(1);
});

it('unsets the previous key task on the same object when a new one is set', function () {
    $object = ShtabObject::factory()->create();
    $old = ShtabTask::factory()->create(['object_id' => $object->id, 'is_key' => true]);

    $this->actingAs(tasksAdmin())
        ->post('/shtab/tasks', ['object_id' => $object->id, 'title' => 'Новая главная', 'is_key' => true])
        ->assertRedirect();

    $new = ShtabTask::query()->where('title', 'Новая главная')->sole();
    expect($new->is_key)->toBeTrue()
        ->and($old->refresh()->is_key)->toBeFalse();

    $this->actingAs(tasksAdmin())
        ->patch("/shtab/tasks/{$old->id}", ['is_key' => true])
        ->assertRedirect();

    expect($old->refresh()->is_key)->toBeTrue()
        ->and($new->refresh()->is_key)->toBeFalse();
});

it('deletes a task without events', function () {
    $task = ShtabTask::factory()->create();

    $this->actingAs(tasksAdmin())
        ->delete("/shtab/tasks/{$task->id}")
        ->assertRedirect();

    expect(ShtabTask::count())->toBe(0)
        ->and(ShtabEvent::count())->toBe(0);
});

it('puts tasks with counts into the board object payload ordered open and key first', function () {
    $object = ShtabObject::factory()->create();
    $person = ShtabPerson::factory()->create();
    $done = ShtabTask::factory()->create(['object_id' => $object->id, 'is_done' => true, 'done_at' => now()]);
    $plain = ShtabTask::factory()->create(['object_id' => $object->id]);
    $key = ShtabTask::factory()->create([
        'object_id' => $object->id,
        'is_key' => true,
        'assignee_person_id' => $person->id,
    ]);

    $row = collect((new BuildShtabBoard)->handle()['objects'])->firstWhere('id', $object->id);

    expect($row['open_tasks'])->toBe(2)
        ->and($row['total_tasks'])->toBe(3)
        ->and(array_column($row['tasks'], 'id'))->toBe([$key->id, $plain->id, $done->id])
        ->and($row['tasks'][0]['is_key'])->toBeTrue()
        ->and($row['tasks'][0]['assignee']['id'])->toBe($person->id)
        ->and($row['tasks'][1]['assignee'])->toBeNull()
        ->and($row['tasks'][2]['is_done'])->toBeTrue();
});

it('puts open key tasks into the board person payload', function () {
    $person = ShtabPerson::factory()->create();
    $object = ShtabObject::factory()->create(['name' => 'Обмен', 'emoji' => '💱']);
    $keyTask = ShtabTask::factory()->create([
        'object_id' => $object->id,
        'assignee_person_id' => $person->id,
        'is_key' => true,
        'title' => 'Запустить обмен USDT',
    ]);
    ShtabTask::factory()->create(['object_id' => $object->id, 'assignee_person_id' => $person->id]);
    ShtabTask::factory()->create([
        'object_id' => ShtabObject::factory()->create()->id,
        'assignee_person_id' => $person->id,
        'is_key' => true,
        'is_done' => true,
        'done_at' => now(),
    ]);

    $row = collect((new BuildShtabBoard)->handle()['people'])->firstWhere('id', $person->id);

    expect($row['key_tasks'])->toBe([[
        'id' => $keyTask->id,
        'object_name' => 'Обмен',
        'object_emoji' => '💱',
        'title' => 'Запустить обмен USDT',
    ]]);
});

it('forbids non-admins from all task routes', function () {
    $task = ShtabTask::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/shtab/tasks', [])->assertForbidden();
    $this->actingAs($user)->patch("/shtab/tasks/{$task->id}", [])->assertForbidden();
    $this->actingAs($user)->delete("/shtab/tasks/{$task->id}")->assertForbidden();
});
