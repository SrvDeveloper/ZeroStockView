<?php

namespace Database\Factories;

use App\Models\ImportTarget;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportTarget>
 */
class ImportTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sort_order' => fake()->unique()->numberBetween(1, 50),
        ];
    }
}
