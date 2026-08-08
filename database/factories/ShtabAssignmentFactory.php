<?php

namespace Database\Factories;

use App\Models\ShtabAssignment;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShtabAssignmentFactory extends Factory
{
    protected $model = ShtabAssignment::class;

    public function definition(): array
    {
        return [
            'person_id' => ShtabPerson::factory(),
            'object_id' => ShtabObject::factory(),
            'role_label' => fake()->randomElement(['владелец', 'аналитика', 'разработка']),
            'started_at' => now()->toDateString(),
            'ended_at' => null,
        ];
    }
}
