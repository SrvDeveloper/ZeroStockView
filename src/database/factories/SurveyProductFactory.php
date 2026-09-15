<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\SurveyProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyProduct>
 */
class SurveyProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'product_code' => fake()->unique()->bothify('ITEM-####'),
            'brand' => fake()->randomElement(['ALPHA', 'BRAVO', 'CHARLIE']),
            'category' => fake()->randomElement(['アウター', 'トップス', 'パンツ']),
            'parent_asin' => fake()->boolean(80) ? 'B'.fake()->unique()->numerify('#########') : null,
            'status' => 'active',
            'source_updated_at' => now()->subHour(),
            'sort_order' => fake()->unique()->numberBetween(1, 50),
            'memo' => null,
        ];
    }
}
