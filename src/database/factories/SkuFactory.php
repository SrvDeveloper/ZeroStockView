<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sku>
 */
class SkuFactory extends Factory
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
            'sku_code' => fake()->unique()->bothify('SKU-########'),
            'child_asin' => fake()->boolean(80) ? 'B'.fake()->unique()->numerify('#########') : null,
            'status' => 'active',
            'tq_item_no' => fake()->unique()->numerify('########'),
            'tq_color_no' => fake()->numerify('##'),
            'tq_size' => fake()->randomElement(['S', 'M', 'L', '']),
            'sort_order' => fake()->unique()->numberBetween(1, 65535),
        ];
    }
}
