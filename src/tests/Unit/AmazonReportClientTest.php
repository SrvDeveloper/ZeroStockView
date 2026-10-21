<?php

namespace Tests\Unit;

use App\Exceptions\AmazonReportException;
use App\Services\AmazonReportClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
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

    public function test_fetch_inventories_requests_both_reports_before_waiting_and_authenticates_once(): void
    {
        $requests = $this->fakeReports(['own' => ['IN_QUEUE', 'DONE'], 'fba' => ['IN_PROGRESS', 'IN_PROGRESS', 'DONE']], 3);

        [$own, $fba] = app(AmazonReportClient::class)->fetchInventories();

        $this->assertSame(['GET_FLAT_FILE_OPEN_LISTINGS_DATA', 'own', [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '12']]], [$own->reportType, $own->reportId, $own->rows]);
        $this->assertSame(['GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA', 'fba', [['asin' => 'B000000002', 'sku' => 'SKU-2', 'quantity' => '3']]], [$fba->reportType, $fba->reportId, $fba->rows]);
        // 作成を2本とも依頼してから待ち始め、できあがったレポートはそれ以上確かめない
        $this->assertSame([
            '認証',
            '作成 GET_FLAT_FILE_OPEN_LISTINGS_DATA', '作成 GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA',
            '状態 own', '状態 fba',
            '状態 own', '状態 fba',
            '状態 fba',
            '文書 own', 'ダウンロード own', '文書 fba', 'ダウンロード fba',
        ], $requests->all());
    }

    public function test_fetch_inventories_uses_fba_inventory_api_when_fba_report_generation_is_fatal(): void
    {
        $requests = $this->fakeReports(['own' => ['IN_PROGRESS', 'DONE'], 'fba' => ['FATAL']]);

        [$own, $fba] = app(AmazonReportClient::class)->fetchInventories();

        $this->assertSame('own', $own->reportId);
        $this->assertSame(['FBA_INVENTORY_API', [['asin' => 'B000000003', 'sku' => '', 'quantity' => '4']]], [$fba->reportType, $fba->rows]);
        $this->assertSame(['状態 own', '状態 fba', '状態 own', '文書 own', 'ダウンロード own', 'FBA在庫一覧'], $requests->slice(3)->values()->all());
    }

    public function test_fetch_inventories_fails_without_downloading_when_own_report_generation_is_fatal(): void
    {
        $requests = $this->fakeReports(['own' => ['IN_PROGRESS', 'FATAL'], 'fba' => ['DONE']]);

        try {
            app(AmazonReportClient::class)->fetchInventories();
            $this->fail('自社在庫のレポートが作れないときは失敗するべきです。');
        } catch (AmazonReportException $exception) {
            $this->assertStringContainsString('FATAL', $exception->getMessage());
        }
        $this->assertSame(['状態 own', '状態 fba', '状態 own'], $requests->slice(3)->values()->all());
    }

    public function test_fetch_inventories_times_out_when_either_report_stays_in_progress(): void
    {
        $requests = $this->fakeReports(['own' => ['DONE'], 'fba' => ['IN_PROGRESS', 'IN_PROGRESS', 'IN_PROGRESS']]);

        try {
            app(AmazonReportClient::class)->fetchInventories();
            $this->fail('制限時間内に完成しないときは失敗するべきです。');
        } catch (AmazonReportException $exception) {
            $this->assertStringContainsString('制限時間内', $exception->getMessage());
        }
        // 待つ回数の上限（2回）はレポートごとに数える
        $this->assertSame(['状態 own', '状態 fba', '状態 fba'], $requests->slice(3)->values()->all());
    }

    /**
     * 自社在庫（own）とFBA在庫（fba）のレポートを返す偽のSP-APIを用意し、受けた要求を記録する。
     *
     * @param  array{own: list<string>, fba: list<string>}  $statuses  レポートID => 状態確認のたびに返す状態
     * @return Collection<int, string>
     */
    private function fakeReports(array $statuses, int $pollAttempts = 2): Collection
    {
        config(['services.amazon_sp_api.poll_attempts' => $pollAttempts]);
        $requests = collect();
        $documents = [
            'own' => "出品者SKU\tASIN\t数量\nSKU-1\tB000000001\t12\n",
            'fba' => "sku\tasin\tafn-fulfillable-quantity\nSKU-2\tB000000002\t3\n",
        ];
        Http::fake(function (Request $request) use ($requests, &$statuses, $documents) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_contains($request->url(), '/auth/o2/token')) {
                $requests->push('認証');

                return Http::response(['access_token' => 'access-token']);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/reports')) {
                $requests->push('作成 '.$request['reportType']);

                return Http::response(['reportId' => $request['reportType'] === 'GET_FLAT_FILE_OPEN_LISTINGS_DATA' ? 'own' : 'fba']);
            }
            if (preg_match('#/reports/(own|fba)$#', $path, $matches) === 1) {
                $requests->push('状態 '.$matches[1]);
                $status = array_shift($statuses[$matches[1]]);

                return Http::response(['processingStatus' => $status] + ($status === 'DONE' ? ['reportDocumentId' => 'doc-'.$matches[1]] : []));
            }
            if (preg_match('#/documents/doc-(own|fba)$#', $path, $matches) === 1) {
                $requests->push('文書 '.$matches[1]);

                return Http::response(['url' => 'https://reports.s3.amazonaws.com/'.$matches[1]]);
            }
            if (preg_match('#^/(own|fba)$#', $path, $matches) === 1 && str_starts_with($request->url(), 'https://reports.s3.amazonaws.com/')) {
                $requests->push('ダウンロード '.$matches[1]);

                return Http::response($documents[$matches[1]]);
            }
            if (str_contains($path, '/fba/inventory/v1/summaries')) {
                $requests->push('FBA在庫一覧');

                return Http::response(['payload' => ['inventorySummaries' => [[
                    'asin' => 'B000000003',
                    'sellerSku' => 'SKU-3',
                    'inventoryDetails' => ['fulfillableQuantity' => 4],
                ]]]]);
            }

            return Http::response([], 404);
        });

        return $requests;
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
