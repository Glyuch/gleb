<?php

namespace Database\Factories;

use App\Models\ShtabPerson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ShtabPersonFactory extends Factory
{
    protected $model = ShtabPerson::class;

    public function definition(): array
    {
        $name = fake()->firstName();

        return [
            'name' => $name,
            'initials' => Str::upper(Str::substr($name, 0, 2)),
            'class' => fake()->randomElement(['Аналитик', 'Маркетолог', 'Разраб', 'Биздев']),
            'color' => fake()->randomElement(['#10B981', '#8B5CF6', '#F59E0B', '#EC4899']),
            'is_direct' => true,
            'is_me' => false,
        ];
    }
}
