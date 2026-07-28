<?php

namespace Modules\Nodes\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class NodesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = \Modules\Nodes\Models\Node::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'path' => fake()->word(),
            'depth' => fake()->numberBetween(1, 100),
            'order' => fake()->word(),
            'active' => fake()->boolean()
        ];
    }
}

