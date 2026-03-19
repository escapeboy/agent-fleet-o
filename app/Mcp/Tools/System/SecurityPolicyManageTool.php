<?php

namespace App\Mcp\Tools\System;

use App\Livewire\Settings\SecurityPolicyPanel;
use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class SecurityPolicyManageTool extends Tool
{
    protected string $name = 'security_policy_manage';

    protected string $description = 'Read or update the organization security policy (blocked commands, allowed paths, approval requirements, timeouts). Operations: get, update, reset.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('get: read current policy | update: save new policy | reset: clear policy to defaults')
                ->enum(['get', 'update', 'reset'])
                ->required(),
            'policy' => $schema->object([
                    'blocked_commands' => $schema->array()->items($schema->string()),
                    'blocked_patterns' => $schema->array()->items($schema->string()),
                    'allowed_commands' => $schema->array()->items($schema->string()),
                    'allowed_paths' => $schema->array()->items($schema->string()),
                    'require_approval_for' => $schema->array()->items($schema->string()),
                    'max_command_timeout' => $schema->integer()->description('Max command timeout in seconds. Null to remove limit.'),
                ])
                ->description('Required for update. Policy fields to set.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $operation = $request->get('operation');

        if (! $operation || ! in_array($operation, ['get', 'update', 'reset'], true)) {
            return Response::error('operation must be one of: get, update, reset');
        }

        return match ($operation) {
            'get' => $this->getPolicy(),
            'update' => $this->updatePolicy($request),
            'reset' => $this->resetPolicy(),
        };
    }

    private function getPolicy(): Response
    {
        $policy = SecurityPolicyPanel::getOrgPolicy();

        return Response::text(json_encode([
            'policy' => [
                'blocked_commands' => $policy['blocked_commands'] ?? [],
                'blocked_patterns' => $policy['blocked_patterns'] ?? [],
                'allowed_commands' => $policy['allowed_commands'] ?? [],
                'allowed_paths' => $policy['allowed_paths'] ?? [],
                'require_approval_for' => $policy['require_approval_for'] ?? [],
                'max_command_timeout' => $policy['max_command_timeout'] ?? null,
            ],
        ]));
    }

    private function updatePolicy(Request $request): Response
    {
        if (! Gate::check('feature.security_policy')) {
            return Response::error('Security policy management is not available on your current plan.');
        }

        $policyInput = $request->get('policy');

        if (empty($policyInput) || ! is_array($policyInput)) {
            return Response::error('policy object is required for update operation.');
        }

        $existing = SecurityPolicyPanel::getOrgPolicy();

        $policy = array_merge($existing, array_filter([
            'blocked_commands' => $policyInput['blocked_commands'] ?? null,
            'blocked_patterns' => $policyInput['blocked_patterns'] ?? null,
            'allowed_commands' => $policyInput['allowed_commands'] ?? null,
            'allowed_paths' => $policyInput['allowed_paths'] ?? null,
            'require_approval_for' => $policyInput['require_approval_for'] ?? null,
            'max_command_timeout' => $policyInput['max_command_timeout'] ?? null,
        ], fn ($v) => $v !== null));

        GlobalSetting::set('org_security_policy', $policy);

        return Response::text(json_encode([
            'success' => true,
            'message' => 'Security policy updated.',
            'policy' => $policy,
        ]));
    }

    private function resetPolicy(): Response
    {
        if (! Gate::check('feature.security_policy')) {
            return Response::error('Security policy management is not available on your current plan.');
        }

        GlobalSetting::set('org_security_policy', []);

        return Response::text(json_encode([
            'success' => true,
            'message' => 'Security policy reset to defaults.',
        ]));
    }
}
