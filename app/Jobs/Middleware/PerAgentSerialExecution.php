<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PerAgentSerialExecution
{
    private const LOCK_PREFIX = 'agent-exec:';

    private const LOCK_TTL = 3600;

    private const MAX_WAIT_SECONDS = 3600;

    private const BACKOFF_SCHEDULE = [10, 30, 60, 120, 120, 120];

    public function handle(object $job, Closure $next): void
    {
        $agentId = $this->resolveAgentId($job);
        if (! $agentId) {
            $next($job);

            return;
        }

        $lockKey = self::LOCK_PREFIX.$agentId;
        $redis = Redis::connection('locks');
        $acquired = (bool) $redis->set($lockKey, (string) getmypid(), 'EX', self::LOCK_TTL, 'NX');

        if ($acquired) {
            try {
                $next($job);
            } finally {
                $redis->del($lockKey);
            }

            return;
        }

        $attempt = $job->attempts();
        $backoffIndex = min($attempt - 1, count(self::BACKOFF_SCHEDULE) - 1);
        $delay = self::BACKOFF_SCHEDULE[$backoffIndex];

        $totalWaited = array_sum(array_slice(self::BACKOFF_SCHEDULE, 0, $attempt));
        if ($totalWaited >= self::MAX_WAIT_SECONDS) {
            Log::warning('PerAgentSerialExecution: max wait exceeded', [
                'agent_id' => $agentId,
                'total_waited' => $totalWaited,
            ]);
            $job->fail(new \RuntimeException(
                "Agent {$agentId} has been busy for over ".(self::MAX_WAIT_SECONDS / 60).' minutes. Maximum queue time exceeded.',
            ));

            return;
        }

        $job->release($delay);
    }

    private function resolveAgentId(object $job): ?string
    {
        if (method_exists($job, 'getAgentId')) {
            return $job->getAgentId();
        }

        if (property_exists($job, 'agentId')) {
            return $job->agentId;
        }

        return null;
    }
}
