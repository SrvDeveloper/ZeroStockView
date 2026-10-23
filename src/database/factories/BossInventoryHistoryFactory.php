<?php

namespace Database\Factories;

use App\Models\BossInventoryHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BossInventoryHistory>
 */
class BossInventoryHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uploadedAt = now();

        return [
            'original_name' => 'InventoryHistories_FromTo_'.fake()->numerify('##############').'.csv',
            'disk' => 'local',
            'stored_path' => 'boss-inventory-histories/'.fake()->uuid().'.csv',
            'mime_type' => 'text/csv',
            'size_bytes' => fake()->numberBetween(1000, 1000000),
            'period_from' => $uploadedAt->copy()->subDays(4)->startOfDay(),
            'period_to' => $uploadedAt->copy()->subDay()->setTime(9, 30),
            'row_count' => fake()->numberBetween(1, 1000),
            'summary' => null,
            'uploaded_by' => null,
            'uploaded_at' => $uploadedAt,
            'expires_at' => $uploadedAt->copy()->addDays(30),
        ];
    }
}
