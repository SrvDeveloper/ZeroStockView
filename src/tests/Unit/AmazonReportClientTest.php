<?php

namespace Tests\Unit;

use App\Exceptions\AmazonReportException;
use App\Services\AmazonReportClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AmazonReportClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.amazon_sp_api' => [
            'endpoint' => 'https://sellingpartnerapi-fe.amazon.com',
            'marketplace_id' => 'A1VC38T7YXB528',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'timeout' => 5,
            'poll_attempts' => 2,
            'poll_interval_seconds' => 0,
            'retry_attempts' => 2,
            'retry_delay_seconds' => 0,
        ]]);
    }

    public function test_fetches_own_inventory_with_japanese_headers(): void
    {
        $this->fakeReport("\xEF\xBB\xBF出品者SKU\tASIN\t数量\nSKU-1\tB000000001\t12\n");

        $report = app(AmazonReportClient::class)->fetchOwnInventory();

        $this->assertSame('GET_FLAT_FILE_OPEN_LISTINGS_DATA', $report->reportType);
        $this->assertSame('report-1', $report->reportId);
        $this->assertSame([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '12']], $report->rows);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/reports/2021-06-30/reports')
            && $request['reportType'] === 'GET_FLAT_FILE_OPEN_LISTINGS_DATA'
            && $request['marketplaceIds'] === ['A1VC38T7YXB528']
            && $request['reportOptions'] === ['preferredReportDocumentLocale' => 'ja_JP']
            && $request->hasHeader('x-amz-access-token', 'access-token'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://reports.s3.amazonaws.com/file')
            && ! $request->hasHeader('Accept')
            && ! $request->hasHeader('x-amz-access-token'));
    }

    public function test_fetches_and_decompresses_fba_inventory(): void
    {
        $this->fakeReport(gzencode(mb_convert_encoding("sku\tasin\tafn-fulfillable-quantity\n商品-2\tB000000002\t3\n", 'SJIS-win', 'UTF-8')), 'GZIP');

        $report = app(AmazonReportClient::class)->fetchFbaInventory();

        $this->assertSame([['asin' => 'B000000002', 'sku' => '商品-2', 'quantity' => '3']], $report->rows);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/reports/2021-06-30/reports')
            && $request['reportType'] === 'GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA'
            && ! isset($request['reportOptions']));
    }

    public function test_retries_rate_limits_and_waits_for_completion(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'access-token'])
            ->push(['errors' => []], 429)
            ->push(['reportId' => 'report-1'])
            ->push(['processingStatus' => 'IN_QUEUE'])
            ->push(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1'])
            ->push(['url' => 'https://reports.s3.amazonaws.com/file'])
            ->push("sku\tasin\tafn-fulfillable-quantity\nSKU-2\tB000000002\t3\n");

        $this->assertCount(1, app(AmazonReportClient::class)->fetchFbaInventory()->rows);
        Http::assertSentCount(7);
    }

    public function test_rejects_authentication_failure_without_exposing_response(): void
    {
        Http::fakeSequence()->push(['error_description' => 'secret response'], 401);

        $this->expectException(AmazonReportException::class);
        $this->expectExceptionMessage('認証または権限を確認');
        app(AmazonReportClient::class)->fetchOwnInventory();
    }

    public function test_rejects_fatal_report_processing(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'access-token'])
            ->push(['reportId' => 'report-1'])
            ->push(['processingStatus' => 'FATAL']);

        $this->expectException(AmazonReportException::class);
        $this->expectExceptionMessage('FATAL');
        app(AmazonReportClient::class)->fetchOwnInventory();
    }

    public function test_uses_fba_inventory_api_when_fba_report_generation_is_fatal(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'access-token'])
            ->push(['reportId' => 'report-1'])
            ->push(['processingStatus' => 'FATAL'])
            ->push([
                'pagination' => ['nextToken' => 'page-2'],
                'payload' => ['inventorySummaries' => [[
                    'asin' => 'B000000001',
                    'sellerSku' => 'SKU-1',
                    'inventoryDetails' => ['fulfillableQuantity' => 3],
                ]]],
            ])
            ->push([
                'payload' => ['inventorySummaries' => [[
                    'asin' => 'B000000001',
                    'sellerSku' => 'SKU-2',
                    'inventoryDetails' => ['fulfillableQuantity' => 2],
                ]]],
            ]);

        $report = app(AmazonReportClient::class)->fetchFbaInventory();

        $this->assertSame('FBA_INVENTORY_API', $report->reportType);
        $this->assertSame([['asin' => 'B000000001', 'sku' => '', 'quantity' => '5']], $report->rows);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/fba/inventory/v1/summaries')
            && str_contains($request->url(), 'nextToken=page-2')
            && $request->hasHeader('x-amz-access-token', 'access-token'));
    }

    public function test_rejects_missing_required_column(): void
    {
        $this->fakeReport("出品者SKU\tASIN\nSKU-1\tB000000001\n");

        $this->expectException(AmazonReportException::class);
        $this->expectExceptionMessage('必要な列');
        app(AmazonReportClient::class)->fetchOwnInventory();
    }

    public function test_rejects_untrusted_document_url(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'access-token'])
            ->push(['reportId' => 'report-1'])
            ->push(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1'])
            ->push(['url' => 'http://127.0.0.1/private']);

        $this->expectException(AmazonReportException::class);
        $this->expectExceptionMessage('文書URLが不正');
        app(AmazonReportClient::class)->fetchOwnInventory();
    }

    public function test_times_out_when_report_stays_in_progress(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'access-token'])
            ->push(['reportId' => 'report-1'])
            ->push(['processingStatus' => 'IN_PROGRESS'])
            ->push(['processingStatus' => 'IN_PROGRESS']);

        $this->expectException(AmazonReportException::class);
        $this->expectExceptionMessage('制限時間内');
        app(AmazonReportClient::class)->fetchOwnInventory();
    }

    public function test_identifies_document_download_connection_failure_without_exposing_signed_url(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/o2/token')) {
                return Http::response(['access_token' => 'access-token']);
            }
            if (str_ends_with($request->url(), '/reports') && $request->method() === 'POST') {
                return Http::response(['reportId' => 'report-1']);
            }
            if (str_ends_with($request->url(), '/reports/report-1')) {
                return Http::response(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1']);
            }
            if (str_ends_with($request->url(), '/documents/doc-1')) {
                return Http::response(['url' => 'https://reports.s3.amazonaws.com/file?signature=secret']);
            }

            throw new ConnectionException('cURL error 6: Could not resolve https://reports.s3.amazonaws.com/file?signature=secret');
        });

        try {
            app(AmazonReportClient::class)->fetchOwnInventory();
            $this->fail('文書ダウンロードの接続失敗を返すべきです。');
        } catch (AmazonReportException $exception) {
            $this->assertStringContainsString('文書ダウンロード', $exception->getMessage());
            $this->assertStringContainsString('名前解決', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    private function fakeReport(string $contents, ?string $compression = null): void
    {
        $document = ['url' => 'https://reports.s3.amazonaws.com/file'];
        if ($compression !== null) {
            $document['compressionAlgorithm'] = $compression;
        }
        Http::fakeSequence()
            ->push(['access_token' => 'access-token'])
            ->push(['reportId' => 'report-1'])
            ->push(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1'])
            ->push($document)
            ->push($contents);
    }
}
