<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Cache\SemanticCachePurgeTool;
use App\Mcp\Tools\Cache\SemanticCacheStatsTool;
use App\Mcp\Tools\Compute\ComputeManageTool;
use App\Mcp\Tools\RunPod\RunPodManageTool;
use App\Mcp\Tools\System\AuditLogTool;
use App\Mcp\Tools\System\BlacklistManageTool;
use App\Mcp\Tools\System\DashboardKpisTool;
use App\Mcp\Tools\System\GlobalSettingsUpdateTool;
use App\Mcp\Tools\System\SecurityPolicyManageTool;
use App\Mcp\Tools\System\SystemHealthTool;
use App\Mcp\Tools\System\SystemVersionCheckTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SystemManageTool extends CompactTool
{
    protected string $name = 'system_manage';

    protected string $description = <<<'TXT'
System administration and monitoring — KPIs, health checks, audit log, semantic cache, compute providers (RunPod). `global_settings`, `blacklist`, `security_policy`, `cache_purge`, and `runpod`/`compute` provisioning require the platform `admin` role; the rest are available to team admins.

Read actions:
- dashboard_kpis — platform-wide KPI snapshot.
- health — system health (DB, cache, queue, Horizon, providers).
- version_check — installed version, available updates.
- audit_log — query audit entries; optional filter (actor, action, entity_type, since).
- cache_stats — semantic cache hit/miss/savings.

Write actions:
- global_settings (PLATFORM ADMIN) — update platform-wide settings (object).
- blacklist (PLATFORM ADMIN) — sub-actions add/remove/list email/domain entries.
- security_policy (PLATFORM ADMIN) — sub-actions on security policies.
- cache_purge (DESTRUCTIVE, PLATFORM ADMIN) — purges semantic cache cross-team.
- compute (PLATFORM ADMIN) — sub-actions on compute resources.
- runpod (PLATFORM ADMIN, costs real $) — sub-actions on RunPod GPU instances; spinning up pods bills the platform account immediately.
TXT;

    protected function toolMap(): array
    {
        return [
            'dashboard_kpis' => DashboardKpisTool::class,
            'health' => SystemHealthTool::class,
            'version_check' => SystemVersionCheckTool::class,
            'audit_log' => AuditLogTool::class,
            'global_settings' => GlobalSettingsUpdateTool::class,
            'blacklist' => BlacklistManageTool::class,
            'security_policy' => SecurityPolicyManageTool::class,
            'cache_stats' => SemanticCacheStatsTool::class,
            'cache_purge' => SemanticCachePurgeTool::class,
            'compute' => ComputeManageTool::class,
            'runpod' => RunPodManageTool::class,
        ];
    }
}
