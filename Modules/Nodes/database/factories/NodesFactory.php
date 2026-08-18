<?php

namespace Modules\Nodes\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;

class NodesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Node::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Node $node): void {
            $node->prepareForCreation();
        });
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'name' => fake()->unique()->word(),
            'active' => fake()->boolean(),
            'institution_id' => Institution::factory(),
        ];
    }
}
