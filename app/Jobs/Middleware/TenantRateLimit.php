<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

class TenantRateLimit
{
    public function __construct(
        private readonly string $queue = 'default',
        private readonly int $maxJobsPerMinute = 60,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $teamId = $job->teamId ?? null;

        if (! $teamId) {
            $next($job);

            return;
        }

        $key = "tenant-rate:{$teamId}:{$this->queue}";
        $now = now()->timestamp;
        $windowStart = $now - 60;

        $redis = Redis::connection('locks');

        // Sliding window: remove expired entries, count current, add new
        $redis->zremrangebyscore($key, '-inf', $windowStart);
        $currentCount = $redis->zcard($key);

        if ($currentCount >= $this->maxJobsPerMinute) {
            // Release back to queue with delay
            $job->release(10);

            return;
        }

        $redis->zadd($key, $now, $now.':'.uniqid());
        $redis->expire($key, 120);

        $next($job);
    }
}
