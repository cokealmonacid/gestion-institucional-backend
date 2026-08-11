<?php

namespace Modules\Nodes\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Nodes\Models\Node;

class NodesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Node::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'name' => fake()->word(),
            'path' => fn (array $attributes) => $attributes['id'],
            'depth' => 0,
            'order' => fake()->numberBetween(1, 1000),
            'active' => fake()->boolean(),
        ];
    }
}
