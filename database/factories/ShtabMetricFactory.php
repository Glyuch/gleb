<?php

namespace Database\Factories;

use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShtabMetric>
 */
class ShtabMetricFactory extends Factory
{
    protected $model = ShtabMetric::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'object_id' => ShtabObject::factory(),
            'name' => fake()->word(),
            'status' => 'green',
            'value_text' => null,
        ];
    }
}
