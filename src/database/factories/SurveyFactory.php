<?php

namespace Database\Factories;

use App\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Survey>
 */
class SurveyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'executed_at' => now(),
            'import_setting_name' => '売上TOP20',
        ];
    }

    public function legacyFile(): static
    {
        return $this->state(fn (): array => [
            'includes_amazon_inventory' => true,
            'amazon_source' => Survey::AMAZON_SOURCE_LEGACY_FILE,
        ]);
    }

    public function spApi(): static
    {
        return $this->state(fn (): array => [
            'includes_amazon_inventory' => false,
            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
        ]);
    }
}
