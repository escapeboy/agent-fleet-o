<?php

namespace FleetQ\BorunaAudit\Services;

class FeatureFlagResolver
{
    public function isEnabled(string $workflowName, string $tenantId): bool
    {
        if (! config('boruna_audit.enabled', true)) {
            return false;
        }

        // Tenant DB setting takes precedence
        $setting = \DB::table('boruna_tenant_settings')
            ->where('team_id', $tenantId)
            ->first();

        if ($setting !== null) {
            if (! $setting->enabled) {
                return false;
            }

            $workflowsEnabled = json_decode($setting->workflows_enabled ?? '{}', true) ?? [];

            if (array_key_exists($workflowName, $workflowsEnabled)) {
                return (bool) $workflowsEnabled[$workflowName];
            }
        }

        // Fall back to per-workflow config
        $workflowConfig = config("boruna_audit.workflows.{$workflowName}");

        if (is_array($workflowConfig)) {
            return (bool) ($workflowConfig['enabled'] ?? true);
        }

        return true;
    }

    public function isShadowMode(string $tenantId): bool
    {
        $setting = \DB::table('boruna_tenant_settings')
            ->where('team_id', $tenantId)
            ->value('shadow_mode');

        if ($setting !== null) {
            return (bool) $setting;
        }

        return config('boruna_audit.shadow_mode', true);
    }
}
