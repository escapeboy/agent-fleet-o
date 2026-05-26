<?php

namespace App\Mcp\Tools\Shared;

use App\Mcp\Concerns\HasStructuredErrors;
use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Register, list, or remove operator-defined local-agent CLIs so the platform can
 * drive agent CLIs beyond the built-in registry (Claude Code, Codex, Gemini, Cursor,
 * Kiro, OpenCode, Cline, Aider, Amp) without a code deploy.
 *
 * Stored in GlobalSetting('local_agents_custom') and merged into the registry by
 * LocalAgentDiscovery::registeredAgents(). Super-admin only: a custom agent's
 * `binary`/`detect_command` are shell-executed on the host.
 */
#[IsDestructive]
class LocalAgentCustomManageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'local_agent_custom_manage';

    protected string $description = 'Manage custom local-agent CLIs (super-admin). Actions: "list" all custom agents, "register" a new CLI (key, binary, execute_flags, …), or "remove" one. Lets the platform run agent CLIs beyond the built-ins without a deploy. The binary and detect_command run on the host, so this is restricted to super-admins.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('list | register | remove')->required(),
            'key' => $schema->string()->description('Agent key (slug, e.g. "copilot"). Required for register/remove.'),
            'name' => $schema->string()->description('Display name (register).'),
            'binary' => $schema->string()->description('Executable name or absolute path, e.g. "copilot" (register).'),
            'detect_command' => $schema->string()->description('Version probe; defaults to "<binary> --version" (register).'),
            'description' => $schema->string()->description('Short description (register).'),
            'execute_flags' => $schema->array()->description('CLI flags for a one-shot prompt run (register).')->items($schema->string()),
            'stream_flags' => $schema->array()->description('CLI flags for streaming; defaults to execute_flags (register).')->items($schema->string()),
            'capabilities' => $schema->array()->description('Capability tags (register).')->items($schema->string()),
            'output_format' => $schema->string()->description('text | json | stream-json (register, default text).'),
            'requires_pty' => $schema->boolean()->description('Whether the CLI needs a PTY (register, default false).'),
            'requires_env' => $schema->string()->description('Env var the CLI needs, e.g. "OPENAI_API_KEY" (register).'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! auth()->user()?->is_super_admin) {
            return $this->permissionDeniedError('Managing custom local agents requires super-admin privileges.');
        }

        $action = $request->get('action');
        $custom = GlobalSetting::get('local_agents_custom', []);
        if (! is_array($custom)) {
            $custom = [];
        }

        return match ($action) {
            'list' => $this->list($custom),
            'register' => $this->register($request, $custom),
            'remove' => $this->remove($request, $custom),
            default => $this->invalidArgumentError("Unknown action '{$action}'. Use list, register, or remove."),
        };
    }

    /**
     * @param  array<string, mixed>  $custom
     */
    private function list(array $custom): Response
    {
        return Response::text(json_encode([
            'custom_agents' => $custom,
            'count' => count($custom),
        ]));
    }

    /**
     * @param  array<string, mixed>  $custom
     */
    private function register(Request $request, array $custom): Response
    {
        $key = (string) $request->get('key', '');
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key) !== 1) {
            return $this->invalidArgumentError('key must be a slug (lowercase letters, digits, hyphen, underscore).');
        }

        // Built-in agents are canonical and must not be shadowed by a custom entry.
        if (array_key_exists($key, config('local_agents.agents', []))) {
            return $this->invalidArgumentError("'{$key}' is a built-in agent and cannot be overridden.");
        }

        $binary = (string) $request->get('binary', '');
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $binary) !== 1) {
            return $this->invalidArgumentError('binary must be an executable name or path with no shell metacharacters.');
        }

        $executeFlags = array_values(array_filter((array) $request->get('execute_flags', []), 'is_string'));
        if ($executeFlags === []) {
            return $this->invalidArgumentError('execute_flags is required and must list the flags for a one-shot prompt run.');
        }

        $custom[$key] = [
            'name' => (string) $request->get('name', $key),
            'binary' => $binary,
            'description' => (string) $request->get('description', 'Custom local agent'),
            'detect_command' => (string) $request->get('detect_command', $binary.' --version'),
            'requires_env' => $request->get('requires_env') ? (string) $request->get('requires_env') : null,
            'capabilities' => array_values(array_filter((array) $request->get('capabilities', ['code_generation']), 'is_string')),
            'supported_modes' => ['sync'],
            'execute_flags' => $executeFlags,
            'stream_flags' => array_values(array_filter((array) $request->get('stream_flags', $executeFlags), 'is_string')),
            'output_format' => (string) $request->get('output_format', 'text'),
            'requires_pty' => (bool) $request->get('requires_pty', false),
        ];

        GlobalSetting::set('local_agents_custom', $custom);

        return Response::text(json_encode([
            'success' => true,
            'registered' => $key,
            'agent' => $custom[$key],
        ]));
    }

    /**
     * @param  array<string, mixed>  $custom
     */
    private function remove(Request $request, array $custom): Response
    {
        $key = (string) $request->get('key', '');
        if (! array_key_exists($key, $custom)) {
            return $this->notFoundError('custom agent');
        }

        unset($custom[$key]);
        GlobalSetting::set('local_agents_custom', $custom);

        return Response::text(json_encode([
            'success' => true,
            'removed' => $key,
        ]));
    }
}
