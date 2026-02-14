<?php

namespace App\Domain\Outbound\Middleware;

use App\Domain\Outbound\Models\OutboundProposal;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Redis;

class TargetRateLimit
{
    /**
     * Check if the target has been contacted too recently.
     * Default: max 1 contact per 7 days per target.
     */
    public function check(OutboundProposal $proposal): bool
    {
        $target = $this->extractTargetKey($proposal);

        if (! $target) {
            return true; // Can't rate limit without a target identifier
        }

        $cooldownDays = GlobalSetting::get('target_cooldown_days', 7);
        $cooldownSeconds = $cooldownDays * 86400;

        $key = "rate_limit:target:{$target}";

        $redis = Redis::connection('cache');

        $lastContact = $redis->get($key);

        if ($lastContact && (now()->timestamp - (int) $lastContact) < $cooldownSeconds) {
            return false;
        }

        // Record this contact
        $redis->setex($key, $cooldownSeconds, now()->timestamp);

        return true;
    }

    private function extractTargetKey(OutboundProposal $proposal): ?string
    {
        $target = $proposal->target;

        if (is_array($target)) {
            return $target['email'] ?? $target['phone'] ?? $target['id'] ?? null;
        }

        return is_string($target) ? $target : null;
    }
}
