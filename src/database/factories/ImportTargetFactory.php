<?php

namespace Database\Factories;

use App\Models\ImportSetting;
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
            'import_setting_id' => ImportSetting::query()->firstOrCreate(['name' => fake()->unique()->words(2, true)])->getKey(),
            'product_id' => Product::factory(),
            'sort_order' => fake()->unique()->numberBetween(1, 50),
        ];
    }
}
