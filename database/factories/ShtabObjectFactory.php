<?php

namespace Database\Factories;

use App\Models\ShtabObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShtabObject>
 */
class ShtabObjectFactory extends Factory
{
    protected $model = ShtabObject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'product',
            'name' => fake()->unique()->word(),
            'emoji' => '🏰',
            'focus_level' => 0,
            'color' => '#5B6EE8',
        ];
    }
}
