<?php

namespace App\Mcp\Tools\System;

use App\Domain\Audit\Services\AuditChainService;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AuditChainVerifyTool extends Tool
{
    protected string $name = 'system_audit_chain_verify';

    protected string $description = 'Verify the tamper-evident hash chain over this team\'s audit log. Returns status (ok/broken), number of entries verified, the first broken entry id if any, and unchained straggler count.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::error('Team context could not be resolved.');
        }

        $reports = app(AuditChainService::class)->verifyChain($teamId);

        return Response::text(json_encode([
            'hash_chain_enabled' => (bool) config('audit.hash_chain.enabled', false),
            'report' => $reports[0] ?? null,
        ]));
    }
}
