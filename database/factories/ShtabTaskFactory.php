<?php

namespace Database\Factories;

use App\Models\ShtabObject;
use App\Models\ShtabTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShtabTask>
 */
class ShtabTaskFactory extends Factory
{
    protected $model = ShtabTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'object_id' => ShtabObject::factory(),
            'title' => fake()->sentence(3),
            'is_done' => false,
            'done_at' => null,
            'assignee_person_id' => null,
            'is_key' => false,
        ];
    }
}
