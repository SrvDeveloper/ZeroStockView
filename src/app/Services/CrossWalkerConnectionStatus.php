<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * CrossWalker への直近の接続結果を、キャッシュに最新 1 件だけ保持する。
 */
class CrossWalkerConnectionStatus
{
    private const CACHE_KEY = 'integrations.crosswalker.last_result';

    public function recordSuccess(): void
    {
        $this->store(['status' => 'succeeded', 'checked_at' => now()->toISOString(), 'message' => null]);
    }

    /**
     * 画面に出してよい要約だけを受け取る。認証情報や URL を含む文字列は渡さない。
     */
    public function recordFailure(string $message): void
    {
        $this->store(['status' => 'failed', 'checked_at' => now()->toISOString(), 'message' => $message]);
    }

    /** @return array{status: 'succeeded'|'failed', checkedAt: string, message: string|null}|null */
    public function latest(): ?array
    {
        $result = Cache::get(self::CACHE_KEY);
        if (! is_array($result)) {
            return null;
        }

        return [
            'status' => $result['status'],
            'checkedAt' => $result['checked_at'],
            'message' => $result['message'],
        ];
    }

    /**
     * 記録の失敗で CrossWalker を使う処理そのものを止めないよう、例外は報告だけにする。
     *
     * @param  array{status: 'succeeded'|'failed', checked_at: string, message: string|null}  $result
     */
    private function store(array $result): void
    {
        try {
            Cache::forever(self::CACHE_KEY, $result);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
