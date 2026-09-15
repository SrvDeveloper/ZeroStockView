<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\SurveyFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyFile>
 */
class SurveyFileFactory extends Factory
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
            'survey_id' => Survey::factory(),
            'file_type' => fake()->randomElement(['在庫商品レポート', 'FBA在庫管理レポート', '倉庫毎の在庫数レポート', 'KEEP一覧表', '在庫一覧照会表']),
            'original_name' => fake()->word().'.csv',
            'disk' => 'local',
            'stored_path' => 'survey-imports/'.fake()->uuid().'.csv',
            'mime_type' => 'text/csv',
            'size_bytes' => fake()->numberBetween(1000, 1000000),
            'uploaded_at' => $uploadedAt,
            'expires_at' => $uploadedAt->copy()->addDays(30),
        ];
    }
}
