<?php

namespace Database\Factories;

use App\Models\SurveyProduct;
use App\Models\SurveySku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveySku>
 */
class SurveySkuFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'survey_product_id' => SurveyProduct::factory(),
            'sku_code' => fake()->unique()->bothify('SKU-########'),
            'child_asin' => fake()->boolean(80) ? 'B'.fake()->unique()->numerify('#########') : null,
            'status' => 'active',
            'tq_item_no' => fake()->numerify('########'),
            'tq_color_no' => fake()->numerify('##'),
            'tq_size' => fake()->randomElement(['S', 'M', 'L', '']),
            'sort_order' => fake()->unique()->numberBetween(1, 65535),
            'amazon_own_stock' => fake()->numberBetween(0, 100),
            'amazon_fba_stock' => fake()->numberBetween(0, 100),
            'boss_own_stock' => fake()->numberBetween(0, 100),
            'boss_rfc_stock' => fake()->numberBetween(0, 100),
            'free_stock' => fake()->numberBetween(0, 100),
            'ec_stock' => fake()->numberBetween(0, 100),
            'memo' => null,
        ];
    }
}
