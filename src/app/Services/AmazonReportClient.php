<?php

namespace App\Services;

use App\Exceptions\AmazonReportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AmazonReportClient
{
    private const OWN_REPORT = 'GET_FLAT_FILE_OPEN_LISTINGS_DATA';

    private const FBA_REPORT = 'GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA';

    private const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;

    /**
     * 自社在庫とFBA在庫のレポートを両方先に作成依頼し、完成を並行して待つ。
     *
     * @return array{0: AmazonInventoryReport, 1: AmazonInventoryReport} 自社在庫, FBA在庫
     */
    public function fetchInventories(): array
    {
        [$own, $fba] = $this->fetch([self::OWN_REPORT, self::FBA_REPORT]);

        return [$own, $fba];
    }

    public function fetchOwnInventory(): AmazonInventoryReport
    {
        return $this->fetch([self::OWN_REPORT])[0];
    }

    public function fetchFbaInventory(): AmazonInventoryReport
    {
        return $this->fetch([self::FBA_REPORT])[0];
    }

    /**
     * @param  list<string>  $reportTypes
     * @return list<AmazonInventoryReport> $reportTypes と同じ順
     */
    private function fetch(array $reportTypes): array
    {
        $settings = $this->settings();
        $token = $this->accessToken($settings);
        $baseUrl = $settings['endpoint'].'/reports/2021-06-30';
        $request = $this->request()->withHeaders(['x-amz-access-token' => $token]);
        $reportIds = [];
        foreach ($reportTypes as $reportType) {
            $body = [
                'reportType' => $reportType,
                'marketplaceIds' => [$settings['marketplace_id']],
            ];
            if ($reportType === self::OWN_REPORT) {
                $body['reportOptions'] = ['preferredReportDocumentLocale' => 'ja_JP'];
            }
            $created = $this->json($this->send(fn (): Response => $request->post($baseUrl.'/reports', $body), 'レポート作成'), 'レポート作成');
            $reportIds[$reportType] = $this->identifier($created, 'reportId');
        }
        $documentIds = $this->waitForDocuments($request, $baseUrl, $reportIds);

        return array_map(fn (string $reportType): AmazonInventoryReport => $documentIds[$reportType] === null
            ? $this->fetchFbaInventorySummaries($request, $settings)
            : $this->download($request, $baseUrl, $reportType, $reportIds[$reportType], $documentIds[$reportType]), $reportTypes);
    }

    private function download(PendingRequest $request, string $baseUrl, string $reportType, string $reportId, string $documentId): AmazonInventoryReport
    {
        $document = $this->json($this->send(fn (): Response => $request->get($baseUrl.'/documents/'.rawurlencode($documentId)), '文書情報取得'), '文書情報取得');
        $url = $this->documentUrl($document);
        $response = $this->send(fn (): Response => $this->request(false)->withoutRedirecting()->get($url), '文書ダウンロード');
        $contents = $response->body();
        if (strlen($contents) > self::MAX_DOCUMENT_BYTES) {
            throw new AmazonReportException('Amazonレポートの文書サイズが上限を超えています。');
        }
        if (isset($document['compressionAlgorithm'])) {
            if ($document['compressionAlgorithm'] !== 'GZIP') {
                throw new AmazonReportException('Amazonレポートの圧縮形式に対応していません。');
            }
            $contents = gzdecode($contents, self::MAX_DOCUMENT_BYTES + 1);
            if ($contents === false || strlen($contents) > self::MAX_DOCUMENT_BYTES) {
                throw new AmazonReportException('Amazonレポートの展開に失敗しました。');
            }
        }

        return new AmazonInventoryReport($reportType, $reportId, now()->toImmutable(), $this->parse($contents, $reportType));
    }

    /** @return array{endpoint: string, marketplace_id: string, client_id: string, client_secret: string, refresh_token: string} */
    private function settings(): array
    {
        $settings = config('services.amazon_sp_api');
        foreach (['endpoint', 'marketplace_id', 'client_id', 'client_secret', 'refresh_token'] as $key) {
            if (! is_array($settings) || ! is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
                throw new AmazonReportException('Amazon SP-APIの接続設定が不足しています。');
            }
        }
        $endpoint = rtrim($settings['endpoint'], '/');
        if ($endpoint !== 'https://sellingpartnerapi-fe.amazon.com') {
            throw new AmazonReportException('Amazon SP-APIの接続先が正しくありません。');
        }
        $settings['endpoint'] = $endpoint;

        return $settings;
    }

    /** @param array<string, string> $settings */
    private function accessToken(array $settings): string
    {
        $response = $this->send(fn (): Response => $this->request()->asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $settings['refresh_token'],
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
        ]), '認証');
        $payload = $this->json($response, '認証');
        $token = $payload['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new AmazonReportException('Amazon SP-APIの認証応答が不正です。');
        }

        return $token;
    }

    /**
     * すべてのレポートの完成を待つ。待つ回数の上限はレポートごとに数え、まだのレポートだけを毎回確かめる。
     * FBAのレポートが作れなかった（FATAL）ときは、文書IDを null にする。
     *
     * @param  array<string, string>  $reportIds  レポートの種類 => レポートID
     * @return array<string, ?string> レポートの種類 => 文書ID
     */
    private function waitForDocuments(PendingRequest $request, string $baseUrl, array $reportIds): array
    {
        $attempts = max(1, (int) config('services.amazon_sp_api.poll_attempts', 40));
        $pending = $reportIds;
        $documentIds = [];
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                sleep(max(0, (int) config('services.amazon_sp_api.poll_interval_seconds', 15)));
            }
            foreach ($pending as $reportType => $reportId) {
                $payload = $this->json($this->send(fn (): Response => $request->get($baseUrl.'/reports/'.rawurlencode($reportId)), '処理状態確認'), '処理状態確認');
                $status = $payload['processingStatus'] ?? null;
                if ($status === 'IN_QUEUE' || $status === 'IN_PROGRESS') {
                    continue;
                }
                if ($status === 'DONE') {
                    $documentIds[$reportType] = $this->identifier($payload, 'reportDocumentId');
                } elseif ($status === 'FATAL' && $reportType === self::FBA_REPORT) {
                    $documentIds[$reportType] = null;
                } elseif ($status === 'CANCELLED' || $status === 'FATAL') {
                    throw new AmazonReportException('Amazonレポートの生成が失敗しました（'.$status.'）。');
                } else {
                    throw new AmazonReportException('Amazonレポートの処理状態が不正です。');
                }
                unset($pending[$reportType]);
            }
            if ($pending === []) {
                return $documentIds;
            }
        }

        throw new AmazonReportException('Amazonレポートの生成が制限時間内に完了しませんでした。');
    }

    /** @param array{endpoint: string, marketplace_id: string, client_id: string, client_secret: string, refresh_token: string} $settings */
    private function fetchFbaInventorySummaries(PendingRequest $request, array $settings): AmazonInventoryReport
    {
        $params = [
            'details' => 'true',
            'granularityType' => 'Marketplace',
            'granularityId' => $settings['marketplace_id'],
            'marketplaceIds' => $settings['marketplace_id'],
        ];
        $quantities = [];
        $seenSellerSkus = [];
        $seenTokens = [];

        for ($page = 0; $page < 400; $page++) {
            $response = $this->send(fn (): Response => $request->get($settings['endpoint'].'/fba/inventory/v1/summaries', $params), 'FBA在庫一覧取得');
            $data = $response->json();
            $items = is_array($data) ? ($data['payload']['inventorySummaries'] ?? null) : null;
            if (! is_array($items)) {
                throw new AmazonReportException('FBA在庫一覧の応答が不正です。');
            }
            foreach ($items as $item) {
                $asin = $item['asin'] ?? null;
                $sku = $item['sellerSku'] ?? null;
                $quantity = $item['inventoryDetails']['fulfillableQuantity'] ?? null;
                if (! is_string($asin) || $asin === '' || ! is_string($sku) || $sku === '' || ! is_int($quantity) || $quantity < 0) {
                    throw new AmazonReportException('FBA在庫一覧の識別子または出荷可能数量が不正です。');
                }
                if (isset($seenSellerSkus[$sku])) {
                    throw new AmazonReportException('FBA在庫一覧のSeller SKUが重複しています。');
                }
                $seenSellerSkus[$sku] = true;
                $quantities[$asin] = ($quantities[$asin] ?? 0) + $quantity;
                if ($quantities[$asin] > 4294967295) {
                    throw new AmazonReportException('FBA在庫一覧の出荷可能数量が上限を超えています。');
                }
            }

            $nextToken = $data['pagination']['nextToken'] ?? null;
            if ($nextToken === null) {
                $rows = [];
                foreach ($quantities as $asin => $quantity) {
                    $rows[] = ['asin' => $asin, 'sku' => '', 'quantity' => (string) $quantity];
                }

                return new AmazonInventoryReport('FBA_INVENTORY_API', 'inventory-summaries', now()->toImmutable(), $rows);
            }
            if (! is_string($nextToken) || $nextToken === '' || isset($seenTokens[$nextToken])) {
                throw new AmazonReportException('FBA在庫一覧のページ送りが不正です。');
            }
            $seenTokens[$nextToken] = true;
            $params['nextToken'] = $nextToken;
            usleep(600000);
        }

        throw new AmazonReportException('FBA在庫一覧のページ数が上限を超えています。');
    }

    private function request(bool $acceptJson = true): PendingRequest
    {
        $timeout = max(5, (int) config('services.amazon_sp_api.timeout', 30));

        $request = Http::connectTimeout(min(10, $timeout))->timeout($timeout);

        return $acceptJson ? $request->acceptJson() : $request;
    }

    /** @param \Closure(): Response $operation */
    private function send(\Closure $operation, string $stage): Response
    {
        $attempts = max(1, (int) config('services.amazon_sp_api.retry_attempts', 3));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $operation();
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    $reason = match (true) {
                        str_contains($exception->getMessage(), 'cURL error 6:') => '名前解決',
                        str_contains($exception->getMessage(), 'cURL error 28:') => 'タイムアウト',
                        str_contains($exception->getMessage(), 'cURL error 60:') => '証明書検証',
                        default => '通信エラー',
                    };
                    throw new AmazonReportException('Amazon SP-APIの'.$stage.'に接続できませんでした（'.$reason.'）。');
                }
                $this->backoff($attempt);

                continue;
            }
            if ($response->successful()) {
                return $response;
            }
            if ($response->status() === 401 || $response->status() === 403) {
                throw new AmazonReportException('Amazon SP-APIの認証または権限を確認してください。');
            }
            if (! in_array($response->status(), [429, 500, 502, 503, 504], true) || $attempt === $attempts) {
                throw new AmazonReportException('Amazon SP-APIの要求が失敗しました（HTTP '.$response->status().'）。');
            }
            $this->backoff($attempt);
        }

        throw new AmazonReportException('Amazon SP-APIの要求が失敗しました。');
    }

    private function backoff(int $attempt): void
    {
        sleep(max(0, (int) config('services.amazon_sp_api.retry_delay_seconds', 2)) * $attempt);
    }

    /** @return array<string, mixed> */
    private function json(Response $response, string $operation): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new AmazonReportException('Amazon SP-APIの'.$operation.'応答が不正です。');
        }

        return is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
    }

    /** @param array<string, mixed> $payload */
    private function identifier(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new AmazonReportException('Amazon SP-APIの識別子が応答にありません。');
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    private function documentUrl(array $document): string
    {
        $url = $document['url'] ?? null;
        if (! is_string($url)) {
            throw new AmazonReportException('Amazonレポートの文書URLがありません。');
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host) || ! str_ends_with($host, '.amazonaws.com') || (isset($parts['port']) && $parts['port'] !== 443) || isset($parts['user']) || isset($parts['pass'])) {
            throw new AmazonReportException('Amazonレポートの文書URLが不正です。');
        }

        return $url;
    }

    /** @return list<array{asin: string, sku: string, quantity: string}> */
    private function parse(string $contents, string $reportType): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new AmazonReportException('Amazonレポートの文字コードを読み取れません。');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new AmazonReportException('Amazonレポートを読み取れません。');
        }
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $headers = fgetcsv($stream, 0, "\t", '"', '');
            if (! is_array($headers)) {
                throw new AmazonReportException('Amazonレポートのヘッダーがありません。');
            }
            $asin = $this->header($headers, ['ASIN', 'asin']);
            $sku = $this->header($headers, $reportType === self::OWN_REPORT ? ['出品者SKU', 'seller-sku', 'sku'] : ['sku']);
            $quantity = $this->header($headers, $reportType === self::OWN_REPORT ? ['数量', 'quantity'] : ['afn-fulfillable-quantity']);
            $rows = [];
            while (($values = fgetcsv($stream, 0, "\t", '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new AmazonReportException('Amazonレポートの列数が一致しません。');
                }
                $rows[] = [
                    'asin' => trim($values[$asin]),
                    'sku' => trim($values[$sku]),
                    'quantity' => trim($values[$quantity]),
                ];
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /** @param list<string> $headers @param list<string> $candidates */
    private function header(array $headers, array $candidates): int
    {
        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $headers, true);
            if ($index !== false) {
                return $index;
            }
        }

        throw new AmazonReportException('Amazonレポートの必要な列がありません。');
    }
}
