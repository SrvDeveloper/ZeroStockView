<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SurveyFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_download_a_saved_file_and_zip(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $survey = Survey::factory()->create();
        $file = SurveyFile::factory()->for($survey)->create();
        Storage::disk('local')->put($file->stored_path, 'file contents');

        $this->get('/api/survey-files/'.$file->id.'/download')->assertOk();
        $this->get('/api/surveys/'.$survey->id.'/files/download')->assertOk();
    }

    public function test_expired_or_missing_files_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $survey = Survey::factory()->create();
        $expired = SurveyFile::factory()->for($survey)->create([
            'stored_path' => 'survey-imports/expired.csv',
            'uploaded_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);
        $missing = SurveyFile::factory()->for($survey)->create([
            'stored_path' => 'survey-imports/missing.csv',
        ]);
        Storage::disk('local')->put($expired->stored_path, 'old contents');

        $this->get('/api/survey-files/'.$expired->id.'/download')->assertNotFound();
        $this->get('/api/survey-files/'.$missing->id.'/download')->assertNotFound();
        $this->get('/api/surveys/'.$survey->id.'/files/download')->assertNotFound();
    }
}
