<?php

namespace App\Mcp\Tools\Signal;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Mcp\Attributes\AssistantTool;

#[IsReadOnly]
#[AssistantTool('read')]
class TicketConnectorTool extends Tool
{
    protected string $name = 'ticket_connector_manage';

    protected string $description = 'Manage ticket and code connectors (GitHub, Jira, Linear). List supported drivers and get webhook setup instructions. GitHub supports: issues, pull_request, push, workflow_run, release.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform: list_drivers | get_setup_instructions')
                ->enum(['list_drivers', 'get_setup_instructions'])
                ->required(),
            'driver' => $schema->string()
                ->description('Connector driver for setup instructions: github | github_issues | jira | linear')
                ->enum(['github', 'github_issues', 'jira', 'linear']),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|string|in:list_drivers,get_setup_instructions',
            'driver' => 'nullable|string|in:github,github_issues,jira,linear',
        ]);

        $action = $validated['action'];

        try {
            return match ($action) {
                'list_drivers' => $this->listDrivers(),
                'get_setup_instructions' => $this->getSetupInstructions($validated['driver'] ?? null),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function listDrivers(): Response
    {
        return Response::text(json_encode([
            'drivers' => [
                [
                    'driver' => 'github',
                    'name' => 'GitHub (all events)',
                    'webhook_url' => url('/api/signals/github'),
                    'signature_header' => 'X-Hub-Signature-256',
                    'events' => ['issues', 'pull_request', 'push', 'workflow_run', 'release'],
                    'config_key' => 'services.github.webhook_secret',
                    'note' => 'Recommended over github_issues — supports all event types.',
                ],
                [
                    'driver' => 'github_issues',
                    'name' => 'GitHub Issues (legacy)',
                    'webhook_url' => url('/api/signals/github-issues'),
                    'signature_header' => 'X-Hub-Signature-256',
                    'events' => ['issues'],
                    'config_key' => 'services.github.webhook_secret',
                    'note' => 'Legacy endpoint, issues only. Use github driver for full event coverage.',
                ],
                [
                    'driver' => 'jira',
                    'name' => 'Jira',
                    'webhook_url' => url('/api/signals/jira'),
                    'signature_header' => 'X-Hub-Signature',
                    'events' => ['jira:issue_created', 'jira:issue_updated'],
                    'config_key' => 'services.jira.webhook_secret',
                ],
                [
                    'driver' => 'linear',
                    'name' => 'Linear',
                    'webhook_url' => url('/api/signals/linear'),
                    'signature_header' => 'Linear-Signature',
                    'events' => ['Issue'],
                    'config_key' => 'services.linear.webhook_secret',
                ],
            ],
        ]));
    }

    private function getSetupInstructions(?string $driver): Response
    {
        if (! $driver) {
            return Response::error('driver parameter required for get_setup_instructions');
        }

        $instructions = match ($driver) {
            'github' => [
                'webhook_url' => url('/api/signals/github'),
                'steps' => [
                    '1. Go to your GitHub repository (or organisation) → Settings → Webhooks → Add webhook',
                    '2. Set Payload URL to: '.url('/api/signals/github'),
                    '3. Set Content type to: application/json',
                    '4. Set Secret and add GITHUB_WEBHOOK_SECRET to your .env',
                    '5. Under "Which events?", select individual events: Issues, Pull requests, Pushes, Workflow runs, Releases',
                    '6. Click "Add webhook"',
                ],
                'env_var' => 'GITHUB_WEBHOOK_SECRET',
                'services_config' => "Add to config/services.php:\n'github' => ['webhook_secret' => env('GITHUB_WEBHOOK_SECRET')]",
                'supported_events' => ['issues', 'pull_request', 'push', 'workflow_run', 'release'],
                'note' => 'ping events are acknowledged automatically without creating a signal.',
            ],
            'github_issues' => [
                'webhook_url' => url('/api/signals/github-issues'),
                'steps' => [
                    '1. Go to your GitHub repository → Settings → Webhooks → Add webhook',
                    '2. Set Payload URL to: '.url('/api/signals/github-issues'),
                    '3. Set Content type to: application/json',
                    '4. Set Secret to any random string — set it as GITHUB_WEBHOOK_SECRET in your .env',
                    '5. Under "Which events would you like to trigger this webhook?", select "Issues"',
                    '6. Click "Add webhook"',
                ],
                'env_var' => 'GITHUB_WEBHOOK_SECRET',
                'services_config' => "Add to config/services.php:\n'github' => ['webhook_secret' => env('GITHUB_WEBHOOK_SECRET')]",
                'note' => 'Legacy endpoint. Use /api/signals/github for full event coverage.',
            ],
            'jira' => [
                'webhook_url' => url('/api/signals/jira'),
                'steps' => [
                    '1. Go to Jira Admin → System → WebHooks → Create a WebHook',
                    '2. Set URL to: '.url('/api/signals/jira'),
                    '3. Under "Events", enable: Issue: created, Issue: updated',
                    '4. Optionally add a JQL filter to restrict which projects trigger signals',
                    '5. Note: Jira admin webhooks support optional secrets via X-Hub-Signature header',
                    '6. Set the secret in Settings → Connectors → Jira (or add JIRA_WEBHOOK_SECRET to your .env on self-hosted)',
                ],
                'env_var' => 'JIRA_WEBHOOK_SECRET',
                'services_config' => "Add to config/services.php:\n'jira' => ['webhook_secret' => env('JIRA_WEBHOOK_SECRET')]",
            ],
            'linear' => [
                'webhook_url' => url('/api/signals/linear'),
                'steps' => [
                    '1. Go to Linear Settings → API → Webhooks → New webhook',
                    '2. Set URL to: '.url('/api/signals/linear'),
                    '3. Set a signing secret — configure it in Settings → Connectors → Linear (or add LINEAR_WEBHOOK_SECRET to your .env on self-hosted)',
                    '4. Enable resource types: Issue',
                    '5. Click "Create webhook"',
                ],
                'env_var' => 'LINEAR_WEBHOOK_SECRET',
                'services_config' => "Add to config/services.php:\n'linear' => ['webhook_secret' => env('LINEAR_WEBHOOK_SECRET')]",
            ],
            default => throw new \InvalidArgumentException("Unknown driver: {$driver}. Valid: github, github_issues, jira, linear"),
        };

        return Response::text(json_encode($instructions));
    }
}
