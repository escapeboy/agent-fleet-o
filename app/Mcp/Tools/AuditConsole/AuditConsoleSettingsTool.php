<?php

namespace App\Mcp\Tools\AuditConsole;

use App\Mcp\Concerns\HasStructuredErrors;
use FleetQ\BorunaAudit\Services\QuotaEnforcer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AuditConsoleSettingsTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'audit_console_settings';

    protected string $description = 'Get or update Boruna Audit Console settings for the current team. Pass action=get to read, action=update to write.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Operation: get or update')
                ->enum(['get', 'update'])
                ->required(),
            'enabled' => $schema->boolean()
                ->description('(update) Enable or disable Boruna Audit Console for this team'),
            'shadow_mode' => $schema->boolean()
                ->description('(update) Run in shadow mode alongside existing logic'),
            'retention_days' => $schema->number()
                ->description('(update) Bundle retention in days (1–3650)'),
            'quota_per_month' => $schema->number()
                ->description('(update) Monthly run quota (null = unlimited)'),
            'workflows_enabled' => $schema->object()
                ->description('(update) Per-workflow on/off flags, e.g. {"driver_scoring": true, "route_approval": false}'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $action = $request->get('action');

        if ($action === 'get') {
            $setting = DB::table('boruna_tenant_settings')->where('team_id', $teamId)->first();
            $quota = app(QuotaEnforcer::class)->usage((string) $teamId);

            return Response::text(json_encode([
                'enabled' => $setting ? (bool) $setting->enabled : true,
                'shadow_mode' => $setting ? (bool) $setting->shadow_mode : true,
                'workflows_enabled' => $setting ? json_decode($setting->workflows_enabled ?? '{}', true) : [],
                'retention_days' => $setting ? (int) $setting->retention_days : 90,
                'quota_per_month' => $setting?->quota_per_month ? (int) $setting->quota_per_month : null,
                'usage' => $quota,
            ]));
        }

        // update
        $data = array_filter([
            'enabled' => $request->get('enabled'),
            'shadow_mode' => $request->get('shadow_mode'),
            'retention_days' => $request->get('retention_days'),
            'quota_per_month' => $request->get('quota_per_month'),
            'workflows_enabled' => $request->get('workflows_enabled') !== null
                ? json_encode($request->get('workflows_enabled'))
                : null,
            'updated_at' => now(),
        ], fn ($v) => $v !== null);

        $exists = DB::table('boruna_tenant_settings')->where('team_id', $teamId)->exists();

        if ($exists) {
            DB::table('boruna_tenant_settings')->where('team_id', $teamId)->update($data);
        } else {
            DB::table('boruna_tenant_settings')->insert(array_merge($data, [
                'id' => (string) Str::uuid(),
                'team_id' => $teamId,
                'created_at' => now(),
            ]));
        }

        return Response::text(json_encode(['updated' => true]));
    }
}
