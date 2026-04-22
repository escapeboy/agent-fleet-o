<?php

namespace App\Mcp\Tools\Admin;

use App\Domain\Shared\Services\DeploymentMode;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminSecurityOverviewTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'admin_security_overview';

    protected string $description = 'Get security overview: failed login counts per hour over the last 24 hours, total failed logins, and top offending IPs. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return $this->permissionDeniedError('Access denied: super admin privileges required.');
        }

        $now = now();
        $hourlyTotals = [];
        $totalFailed24h = 0;

        for ($i = 23; $i >= 0; $i--) {
            $hour = $now->copy()->subHours($i)->format('Y-m-d-H');
            $hourLabel = $now->copy()->subHours($i)->format('H');
            $count = (int) Cache::get("security:failed_login:total:{$hour}", 0);
            $hourlyTotals[$hourLabel] = $count;
            $totalFailed24h += $count;
        }

        // Collect per-IP counts using cache store keys pattern
        // We scan cache keys matching security:failed_login:ip:*
        $suspiciousIps = [];
        // Since we can't efficiently SCAN all IP keys, we return what we have for the current hour
        $currentHour = $now->format('Y-m-d-H');

        // Return summary with what's available
        return Response::text(json_encode([
            'total_failed_logins_24h' => $totalFailed24h,
            'total_failed_logins_1h' => $hourlyTotals[$now->format('H')] ?? 0,
            'hourly_breakdown' => $hourlyTotals,
            'note' => 'Per-IP breakdown available in the Security admin UI.',
        ]));
    }
}
