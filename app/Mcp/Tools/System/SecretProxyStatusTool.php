<?php

namespace App\Mcp\Tools\System;

use App\Infrastructure\AI\Services\RunSecretVault;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class SecretProxyStatusTool extends Tool
{
    protected string $name = 'secret_proxy_status';

    protected string $description = 'Report secret-proxy (claude-code-vps credential isolation) status: enabled flag, configuration completeness, whether the feature is actually engaged, active run-vault entry count, and recent denied-egress events (potential exfiltration signals).';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $enabled = (bool) config('secret_proxy.enabled');
        $configured = (string) config('secret_proxy.base_url') !== ''
            && (string) config('secret_proxy.key') !== '';

        // Probe the daemon directly — config can say "engaged" while the daemon
        // is down, in which case every run fails closed (no leak) but silently.
        $daemonReachable = null;
        $baseUrl = (string) config('secret_proxy.base_url');
        if ($baseUrl !== '') {
            try {
                $daemonReachable = Http::timeout(2)->get(rtrim($baseUrl, '/').'/healthz')->successful();
            } catch (\Throwable $e) {
                $daemonReachable = false;
            }
        }

        $activeRuns = 0;
        $recentDenied = [];
        $degraded = false;

        try {
            $activeRuns = app(RunSecretVault::class)->activeCount();

            $conn = Redis::connection((string) config('secret_proxy.redis_connection', 'secret_proxy'));
            $tail = $conn->lrange('secret_proxy:audit', -20, -1);
            if (is_array($tail)) {
                foreach ($tail as $entry) {
                    $decoded = json_decode((string) $entry, true);
                    if (is_array($decoded) && ($decoded['kind'] ?? null) === 'denied_egress') {
                        $recentDenied[] = $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            $degraded = true; // Redis / daemon unreachable — report rather than throw.
        }

        return Response::text(json_encode([
            'enabled' => $enabled,
            'configured' => $configured,
            'engaged' => $enabled && $configured,
            'daemon_reachable' => $daemonReachable,
            'allowlist_strict' => (bool) config('secret_proxy.allowlist_strict'),
            'active_run_vaults' => $activeRuns,
            'recent_denied_egress' => $recentDenied,
            'degraded' => $degraded,
        ]));
    }
}
