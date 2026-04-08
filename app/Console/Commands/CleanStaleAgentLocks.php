<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CleanStaleAgentLocks extends Command
{
    protected $signature = 'agents:clean-locks';

    protected $description = 'Clean stale per-agent execution locks';

    public function handle(): int
    {
        $prefix = config('database.redis.options.prefix', '');
        $pattern = $prefix.'agent-exec:*';

        $keys = Redis::connection('locks')->keys($pattern);
        $cleaned = 0;

        foreach ($keys as $key) {
            $cleanKey = str_replace($prefix, '', $key);
            $ttl = Redis::connection('locks')->ttl($cleanKey);

            if ($ttl === -1) {
                Redis::connection('locks')->del($cleanKey);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->components->info("Cleaned {$cleaned} stale agent lock(s).");
        }

        return self::SUCCESS;
    }
}
