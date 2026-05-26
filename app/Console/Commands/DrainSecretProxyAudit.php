<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Drains the secret-proxy daemon's egress audit events from Redis into the
 * audit log. Only denied-egress events are persisted (potential exfiltration
 * signals); high-volume "proxied" events are popped and discarded so the list
 * cannot grow unbounded.
 */
class DrainSecretProxyAudit extends Command
{
    protected $signature = 'secret-proxy:drain-audit {--limit=500}';

    protected $description = 'Drain secret-proxy daemon egress audit events from Redis into the audit log.';

    public function handle(): int
    {
        $conn = Redis::connection((string) config('secret_proxy.redis_connection', 'secret_proxy'));
        $limit = (int) $this->option('limit');
        $persisted = 0;

        for ($i = 0; $i < $limit; $i++) {
            $raw = $conn->lpop('secret_proxy:audit');
            if (! is_string($raw) || $raw === '') {
                break;
            }

            $event = json_decode($raw, true);
            if (! is_array($event) || ($event['kind'] ?? null) !== 'denied_egress') {
                continue;
            }

            AuditEntry::create([
                'event' => 'secret_proxy.denied_egress',
                'properties' => [
                    'route' => $event['route'] ?? null,
                    'host' => $event['host'] ?? null,
                    'observed_at' => $event['ts'] ?? null,
                ],
                'created_at' => now(),
            ]);
            $persisted++;
        }

        $this->info("secret-proxy: persisted {$persisted} denied-egress event(s).");

        return self::SUCCESS;
    }
}
