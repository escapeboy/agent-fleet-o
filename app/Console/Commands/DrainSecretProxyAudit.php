<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
        $skipped = 0;

        // audit_entries.team_id is NOT NULL (multi-tenancy invariant) — these
        // events are platform-wide so we attribute them to a designated audit
        // team. Falls back to the first super-admin team to keep the cron
        // working out of the box without an explicit config.
        $auditTeamId = $this->resolveAuditTeamId();

        for ($i = 0; $i < $limit; $i++) {
            $raw = $conn->lpop('secret_proxy:audit');
            if (! is_string($raw) || $raw === '') {
                break;
            }

            $event = json_decode($raw, true);
            if (! is_array($event) || ($event['kind'] ?? null) !== 'denied_egress') {
                continue;
            }

            if ($auditTeamId === null) {
                $skipped++;

                continue;
            }

            AuditEntry::create([
                'team_id' => $auditTeamId,
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

        if ($skipped > 0) {
            Log::warning('DrainSecretProxyAudit: skipped events — no audit team configured', [
                'skipped' => $skipped,
            ]);
        }

        $this->info("secret-proxy: persisted {$persisted} denied-egress event(s)" . ($skipped > 0 ? " (skipped {$skipped}, no team)" : '') . '.');

        return self::SUCCESS;
    }

    private function resolveAuditTeamId(): ?string
    {
        $configured = config('secret_proxy.audit_team_id');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return Team::query()
            ->withoutGlobalScopes()
            ->whereHas('users', fn ($q) => $q->where('is_super_admin', true))
            ->orderBy('created_at')
            ->value('id');
    }
}
