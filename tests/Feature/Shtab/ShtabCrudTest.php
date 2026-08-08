<?php

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\User;

function crudAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('creates and updates a person', function () {
    $this->actingAs(crudAdmin())
        ->post('/shtab/people', [
            'name' => 'Вика',
            'initials' => 'ВК',
            'class' => 'Аналитик',
            'color' => '#8B5CF6',
            'is_direct' => true,
        ])
        ->assertRedirect();

    $person = ShtabPerson::sole();
    expect($person->name)->toBe('Вика')
        ->and(ShtabEvent::query()->where('type', 'person_created')->count())->toBe(1);

    $this->actingAs(crudAdmin())
        ->put("/shtab/people/{$person->id}", [
            'name' => 'Вика Соколова',
            'initials' => 'ВК',
            'class' => 'Аналитик',
            'color' => '#8B5CF6',
            'is_direct' => false,
        ])
        ->assertRedirect();

    expect($person->refresh()->name)->toBe('Вика Соколова')
        ->and($person->is_direct)->toBeFalse();
});

it('blocks archiving a person with active assignments', function () {
    $assignment = ShtabAssignment::factory()->create();

    $this->actingAs(crudAdmin())
        ->from('/shtab')
        ->post("/shtab/people/{$assignment->person_id}/archive")
        ->assertSessionHasErrors('person');

    expect($assignment->person->refresh()->archived_at)->toBeNull();
});

it('archives a person after assignments are ended', function () {
    $assignment = ShtabAssignment::factory()->create(['ended_at' => now()->toDateString()]);

    $this->actingAs(crudAdmin())
        ->post("/shtab/people/{$assignment->person_id}/archive")
        ->assertRedirect();

    expect($assignment->person->refresh()->archived_at)->not->toBeNull()
        ->and(ShtabEvent::query()->where('type', 'person_archived')->count())->toBe(1);
});

it('creates a project inside a product and validates type', function () {
    $product = ShtabObject::factory()->create(['type' => 'product']);

    $this->actingAs(crudAdmin())
        ->post('/shtab/objects', [
            'type' => 'project',
            'parent_id' => $product->id,
            'name' => 'Запуск v2',
            'description' => 'Вывод второй версии на рынок',
            'emoji' => '🚀',
            'focus_level' => 1,
            'color' => '#14B8A6',
        ])
        ->assertRedirect();

    $project = ShtabObject::query()->where('type', 'project')->sole();
    expect($project->parent->is($product))->toBeTrue()
        ->and($project->description)->toBe('Вывод второй версии на рынок')
        ->and(ShtabEvent::query()->where('type', 'object_created')->count())->toBe(1);

    $this->actingAs(crudAdmin())
        ->from('/shtab')
        ->post('/shtab/objects', ['type' => 'kingdom', 'name' => 'x'])
        ->assertSessionHasErrors('type');
});

it('blocks archiving an object with active assignments', function () {
    $assignment = ShtabAssignment::factory()->create();

    $this->actingAs(crudAdmin())
        ->from('/shtab')
        ->post("/shtab/objects/{$assignment->object_id}/archive")
        ->assertSessionHasErrors('object');
});

it('archives an empty object with an event', function () {
    $object = ShtabObject::factory()->create();

    $this->actingAs(crudAdmin())
        ->post("/shtab/objects/{$object->id}/archive")
        ->assertRedirect();

    expect($object->refresh()->archived_at)->not->toBeNull()
        ->and(ShtabEvent::query()->where('type', 'object_archived')->count())->toBe(1);
});

it('creates, updates and deletes a metric', function () {
    $object = ShtabObject::factory()->create();

    $this->actingAs(crudAdmin())
        ->post('/shtab/metrics', ['object_id' => $object->id, 'name' => 'маржа', 'status' => 'green'])
        ->assertRedirect();

    $metric = ShtabMetric::sole();

    $this->actingAs(crudAdmin())
        ->put("/shtab/metrics/{$metric->id}", ['name' => 'маржа %', 'object_id' => null])
        ->assertRedirect();

    expect($metric->refresh()->name)->toBe('маржа %')
        ->and($metric->object_id)->toBeNull();

    $this->actingAs(crudAdmin())
        ->delete("/shtab/metrics/{$metric->id}")
        ->assertRedirect();

    expect(ShtabMetric::count())->toBe(0);
});

it('stores null parent_id when creating a product with a parent_id set', function () {
    $parent = ShtabObject::factory()->create(['type' => 'product']);

    $this->actingAs(crudAdmin())
        ->post('/shtab/objects', [
            'type' => 'product',
            'parent_id' => $parent->id,
            'name' => 'Новый продукт',
            'emoji' => '📦',
            'focus_level' => 0,
            'color' => '#5B6EE8',
        ])
        ->assertRedirect();

    $product = ShtabObject::query()->where('name', 'Новый продукт')->sole();
    expect($product->parent_id)->toBeNull();
});
