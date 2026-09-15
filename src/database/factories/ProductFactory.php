<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_code' => fake()->unique()->bothify('ITEM-####'),
            'brand' => fake()->randomElement(['ALPHA', 'BRAVO', 'CHARLIE']),
            'category' => fake()->randomElement(['アウター', 'トップス', 'パンツ']),
            'parent_asin' => fake()->boolean(80) ? 'B'.fake()->unique()->numerify('#########') : null,
            'status' => 'active',
            'source_updated_at' => now()->subHour(),
            'synced_at' => now(),
        ];
    }
}
