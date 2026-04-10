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

    protected string $description = 'System administration and monitoring. Actions: dashboard_kpis (overview metrics), health (system health check), version_check, audit_log (query audit entries), global_settings (update platform settings), blacklist (manage email/domain blacklist), security_policy (manage security policies), cache_stats (semantic cache statistics), cache_purge (purge semantic cache), compute (manage compute resources), runpod (manage RunPod GPU instances).';

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
