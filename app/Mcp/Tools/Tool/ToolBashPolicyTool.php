<?php

namespace App\Mcp\Tools\Tool;

use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ToolBashPolicyTool extends Tool
{
    protected string $name = 'tool_bash_policy';

    protected string $description = 'View or update the organization-level command security policy for Bash/shell tools. '
        .'This policy applies to all built_in bash tools and sits between platform-level blocks and per-tool allowlists. '
        .'Actions: get — show current policy; set — replace policy fields; reset — clear all org-level restrictions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: get, set, or reset')
                ->enum(['get', 'set', 'reset'])
                ->required(),
            'blocked_commands' => $schema->array()
                ->description('Commands to block at org level (e.g. ["curl", "wget"])'),
            'blocked_patterns' => $schema->array()
                ->description('Shell patterns to block (e.g. ["--output /etc", "| nc "])'),
            'allowed_commands' => $schema->array()
                ->description('If set, only these commands pass through at org level (whitelist)'),
            'allowed_paths' => $schema->array()
                ->description('If set, working directory must be under one of these paths'),
            'require_approval_for' => $schema->array()
                ->description('Commands matching these patterns require approval/audit log'),
            'max_command_timeout' => $schema->number()
                ->description('Maximum allowed timeout in seconds for any bash command'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|string|in:get,set,reset',
            'blocked_commands' => 'nullable|array',
            'blocked_patterns' => 'nullable|array',
            'allowed_commands' => 'nullable|array',
            'allowed_paths' => 'nullable|array',
            'require_approval_for' => 'nullable|array',
            'max_command_timeout' => 'nullable|integer|min:1|max:3600',
        ]);

        return match ($validated['action']) {
            'get' => $this->get(),
            'set' => $this->set($validated),
            'reset' => $this->reset(),
            default => Response::error('Unknown action'),
        };
    }

    private function get(): Response
    {
        $policy = GlobalSetting::get('org_security_policy', []);

        if (empty($policy)) {
            return Response::text('No org-level security policy set. All commands pass through to tool-level allowlists.');
        }

        $lines = [];
        if (! empty($policy['blocked_commands'])) {
            $lines[] = 'blocked_commands: '.implode(', ', $policy['blocked_commands']);
        }
        if (! empty($policy['blocked_patterns'])) {
            $lines[] = 'blocked_patterns: '.implode(', ', $policy['blocked_patterns']);
        }
        if (! empty($policy['allowed_commands'])) {
            $lines[] = 'allowed_commands (whitelist): '.implode(', ', $policy['allowed_commands']);
        }
        if (! empty($policy['allowed_paths'])) {
            $lines[] = 'allowed_paths: '.implode(', ', $policy['allowed_paths']);
        }
        if (! empty($policy['require_approval_for'])) {
            $lines[] = 'require_approval_for: '.implode(', ', $policy['require_approval_for']);
        }
        if (isset($policy['max_command_timeout'])) {
            $lines[] = "max_command_timeout: {$policy['max_command_timeout']}s";
        }

        return Response::text("Organization security policy:\n\n".implode("\n", $lines));
    }

    private function set(array $input): Response
    {
        $current = GlobalSetting::get('org_security_policy', []);

        $fields = ['blocked_commands', 'blocked_patterns', 'allowed_commands', 'allowed_paths', 'require_approval_for', 'max_command_timeout'];

        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                $current[$field] = $input[$field];
            }
        }

        // Remove empty arrays to keep storage clean
        $current = array_filter($current, fn ($v) => ! empty($v));

        GlobalSetting::set('org_security_policy', $current);

        return Response::text("Organization security policy updated.\n\n".json_encode($current, JSON_PRETTY_PRINT));
    }

    private function reset(): Response
    {
        GlobalSetting::set('org_security_policy', []);

        return Response::text('Organization security policy reset. No org-level restrictions are active.');
    }
}
