<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ログインIDごとに失敗した回数を数え、上限に達したら一定時間ロックする。
 * ロックは時間が過ぎるか、特権管理者が解除すると外れる。ログインに成功すると失敗の回数を数え直す。
 * 存在しないログインIDも同じように数えてロックし、ロックされるかどうかでアカウントの有無が分からないようにする。
 */
class LoginLockout
{
    /** この回数失敗するとロックする（最初の失敗から LOCK_MINUTES 分以内に数えた回数） */
    public const MAX_ATTEMPTS = 5;

    /** ロックする時間（分） */
    public const LOCK_MINUTES = 15;

    /** ロック中なら解除される日時、ロックされていなければ null */
    public function lockedUntil(string $loginId): ?CarbonImmutable
    {
        $timestamp = Cache::get($this->lockKey($loginId));
        if (! is_int($timestamp) || $timestamp <= now()->getTimestamp()) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone'));
    }

    /**
     * 失敗を1回数える。上限に達したらロックし、解除される日時を返す。
     */
    public function recordFailure(string $loginId): ?CarbonImmutable
    {
        $attempts = RateLimiter::hit($this->attemptsKey($loginId), self::LOCK_MINUTES * 60);
        if ($attempts < self::MAX_ATTEMPTS) {
            return null;
        }

        $until = CarbonImmutable::now()->addMinutes(self::LOCK_MINUTES);
        Cache::put($this->lockKey($loginId), $until->getTimestamp(), $until);
        RateLimiter::clear($this->attemptsKey($loginId));

        return $until;
    }

    /** ロックと失敗の回数を消す（ログインの成功と、特権管理者による解除） */
    public function clear(string $loginId): void
    {
        Cache::forget($this->lockKey($loginId));
        RateLimiter::clear($this->attemptsKey($loginId));
    }

    /** ログインIDは大文字・小文字を区別せずに照合されるので、同じ扱いにする */
    private function attemptsKey(string $loginId): string
    {
        return 'login-attempts:'.sha1(mb_strtolower(trim($loginId)));
    }

    private function lockKey(string $loginId): string
    {
        return 'login-lock:'.sha1(mb_strtolower(trim($loginId)));
    }
}
