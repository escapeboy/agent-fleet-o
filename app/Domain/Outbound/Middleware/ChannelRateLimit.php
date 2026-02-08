<?php

namespace App\Domain\Outbound\Middleware;

use App\Domain\Outbound\Models\OutboundProposal;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Redis;

class ChannelRateLimit
{
    /**
     * Check if the channel rate limit has been exceeded.
     * Uses Redis sorted sets with timestamps for sliding window.
     *
     * @throws \App\Domain\Outbound\Exceptions\RateLimitExceededException
     */
    public function check(OutboundProposal $proposal): bool
    {
        $channel = $proposal->channel->value;
        $experimentId = $proposal->experiment_id;

        $limits = GlobalSetting::get('channel_rate_limits', [
            'email' => 10,
            'telegram' => 20,
            'slack' => 20,
            'webhook' => 50,
        ]);

        $limit = $limits[$channel] ?? 50;
        $window = 3600; // 1 hour

        $key = "rate_limit:channel:{$channel}:{$experimentId}";
        $now = now()->timestamp;

        $redis = Redis::connection('cache');

        // Remove entries older than the window
        $redis->zremrangebyscore($key, '-inf', $now - $window);

        // Count current entries in window
        $count = $redis->zcard($key);

        if ($count >= $limit) {
            return false;
        }

        // Add this request
        $redis->zadd($key, $now, $proposal->id);
        $redis->expire($key, $window + 60);

        return true;
    }
}
