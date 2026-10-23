<?php

namespace Database\Factories;

use App\Models\BossInventoryHistory;
use App\Models\Survey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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

    /** BOSS 在庫を在庫変動履歴から再現した調査（その日の 9:00 時点） */
    public function history(): static
    {
        return $this->state(fn (): array => [
            'boss_source' => Survey::BOSS_SOURCE_HISTORY,
            // create() で上書きした実行日時に合わせるため、属性がそろってから計算する
            'boss_basis_at' => fn (array $attributes) => Carbon::parse($attributes['executed_at'])->setTimezone('Asia/Tokyo')->setTime(9, 0),
            'boss_inventory_history_id' => BossInventoryHistory::factory(),
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
