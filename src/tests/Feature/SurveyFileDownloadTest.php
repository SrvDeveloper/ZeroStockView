<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

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

    public function test_zip_contains_each_saved_file_with_its_original_name_and_contents(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $survey = Survey::factory()->create(['executed_at' => '2026-10-21 09:30:00']);
        $contents = [
            '倉庫毎の在庫数レポート.csv' => mb_convert_encoding("倉庫,SKUコード\r\n自社倉庫,A-1\r\n", 'SJIS-win', 'UTF-8'),
            'KEEP一覧表.csv' => "\u{FEFF}品番,キープ残数\n".str_repeat("A,1\n", 1000),
            '在庫一覧照会表.csv' => random_bytes(256),
        ];
        $types = ['倉庫毎の在庫数レポート', 'KEEP一覧表', '在庫一覧照会表'];
        foreach (array_keys($contents) as $index => $name) {
            $file = SurveyFile::factory()->for($survey)->create(['file_type' => $types[$index], 'original_name' => $name]);
            Storage::disk('local')->put($file->stored_path, $contents[$name]);
        }
        // 期限切れのファイルは入れない
        SurveyFile::factory()->for($survey)->create(['file_type' => '在庫商品レポート', 'expires_at' => now()->subDay()]);

        $response = $this->get('/api/surveys/'.$survey->id.'/files/download')->assertOk();
        $this->assertStringContainsString(rawurlencode('202610210930_取込ファイル一式.zip'), $response->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
        }
        $zip->close();
        ksort($contents);
        ksort($entries);
        $this->assertSame($contents, $entries);
    }

    public function test_expired_or_missing_files_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $survey = Survey::factory()->create();
        $expired = SurveyFile::factory()->for($survey)->create([
            'file_type' => 'KEEP一覧表',
            'stored_path' => 'survey-imports/expired.csv',
            'uploaded_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);
        $missing = SurveyFile::factory()->for($survey)->create([
            'file_type' => '在庫一覧照会表',
            'stored_path' => 'survey-imports/missing.csv',
        ]);
        Storage::disk('local')->put($expired->stored_path, 'old contents');

        $this->get('/api/survey-files/'.$expired->id.'/download')->assertNotFound();
        $this->get('/api/survey-files/'.$missing->id.'/download')->assertNotFound();
        $this->get('/api/surveys/'.$survey->id.'/files/download')->assertNotFound();
    }
}
