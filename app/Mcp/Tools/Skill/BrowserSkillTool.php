<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class BrowserSkillTool extends Tool
{
    protected string $name = 'browser_skill_manage';

    protected string $description = 'Manage browser automation skills and inspect recent executions. List configured browser skills, get the configuration schema, or list recent screenshot/scrape/pdf executions. Note: browser skills require BROWSER_SKILL_ENABLED=true and the browserless Docker service.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list_skills | list_executions | get_config_schema | check_status')
                ->enum(['list_skills', 'list_executions', 'get_config_schema', 'check_status'])
                ->required(),
            'limit' => $schema->integer()
                ->description('For list_executions: max results (default 20).'),
            'status' => $schema->string()
                ->description('For list_executions: filter by status (completed, failed).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|in:list_skills,list_executions,get_config_schema,check_status',
            'limit' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string',
        ]);

        return match ($validated['action']) {
            'list_skills' => $this->listSkills(),
            'list_executions' => $this->listExecutions($validated['status'] ?? null, $validated['limit'] ?? 20),
            'get_config_schema' => $this->getConfigSchema(),
            'check_status' => $this->checkStatus(),
            default => Response::error('Unknown action.'),
        };
    }

    private function listSkills(): Response
    {
        $skills = Skill::where('type', SkillType::Browser->value)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'configuration']);

        return Response::text(json_encode([
            'count' => $skills->count(),
            'skills' => $skills->map(function ($s) {
                $cfg = is_array($s->configuration) ? $s->configuration : [];

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'description' => $s->description,
                    'action' => $cfg['action'] ?? 'scrape',
                    'url' => $cfg['url'] ?? null,
                    'wait_for' => $cfg['wait_for'] ?? 2000,
                    'viewport_width' => $cfg['viewport_width'] ?? 1280,
                    'viewport_height' => $cfg['viewport_height'] ?? 720,
                ];
            }),
        ]));
    }

    private function listExecutions(?string $status, int $limit): Response
    {
        $query = SkillExecution::whereHas('skill', fn ($q) => $q->where('type', SkillType::Browser->value))
            ->with('skill:id,name,slug')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($status) {
            $query->where('status', $status);
        }

        $executions = $query->get();

        return Response::text(json_encode([
            'count' => $executions->count(),
            'executions' => $executions->map(fn ($e) => [
                'id' => $e->id,
                'skill_id' => $e->skill_id,
                'skill_name' => $e->skill?->name,
                'status' => $e->status,
                'action' => $e->input['action'] ?? $e->output['action'] ?? null,
                'url' => $e->input['url'] ?? $e->output['url'] ?? null,
                'duration_ms' => $e->duration_ms,
                'output_size_bytes' => $e->output['size_bytes'] ?? null,
                'error_message' => $e->error_message,
                'created_at' => $e->created_at,
            ]),
        ]));
    }

    private function getConfigSchema(): Response
    {
        return Response::text(json_encode([
            'description' => 'Configuration schema for browser skill type.',
            'input' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['screenshot', 'scrape', 'pdf'],
                    'description' => 'Browser action to perform.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'URL to visit. Can also be set in skill configuration as a default.',
                ],
            ],
            'configuration' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['screenshot', 'scrape', 'pdf'],
                    'default' => 'scrape',
                    'description' => 'Default action if not provided in input.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'Default URL to visit if not provided in input.',
                ],
                'wait_for' => [
                    'type' => 'integer',
                    'default' => 2000,
                    'description' => 'Milliseconds to wait after page load before capturing.',
                ],
                'viewport_width' => [
                    'type' => 'integer',
                    'default' => 1280,
                    'description' => 'Browser viewport width in pixels.',
                ],
                'viewport_height' => [
                    'type' => 'integer',
                    'default' => 720,
                    'description' => 'Browser viewport height in pixels.',
                ],
                'full_page' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'For screenshot: capture full scrollable page height.',
                ],
                'screenshot_type' => [
                    'type' => 'string',
                    'enum' => ['png', 'jpeg'],
                    'default' => 'png',
                    'description' => 'Image format for screenshot action.',
                ],
                'pdf_format' => [
                    'type' => 'string',
                    'default' => 'A4',
                    'description' => 'Paper format for pdf action (A4, Letter, etc.).',
                ],
            ],
            'output' => [
                'screenshot' => [
                    'action' => 'screenshot',
                    'url' => 'string',
                    'content_type' => 'image/png',
                    'data' => 'base64-encoded PNG',
                    'size_bytes' => 'integer',
                ],
                'scrape' => [
                    'action' => 'scrape',
                    'url' => 'string',
                    'content' => 'rendered HTML (max 100k chars)',
                    'text' => 'stripped plain text (max 50k chars)',
                    'content_length' => 'integer',
                ],
                'pdf' => [
                    'action' => 'pdf',
                    'url' => 'string',
                    'content_type' => 'application/pdf',
                    'data' => 'base64-encoded PDF',
                    'size_bytes' => 'integer',
                ],
            ],
            'notes' => [
                'Zero cost — no LLM calls are made.',
                'Requires BROWSER_SKILL_ENABLED=true in .env.',
                'Start browserless: docker compose --profile browser up -d',
                'screenshot and pdf return base64-encoded binary data.',
            ],
        ]));
    }

    private function checkStatus(): Response
    {
        $enabled = config('browser.enabled', false);
        $url = config('browser.url', 'http://browserless:3000');
        $reachable = false;
        $error = null;

        if ($enabled) {
            try {
                $response = Http::timeout(5)->get("{$url}/json");
                $reachable = $response->successful();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return Response::text(json_encode([
            'enabled' => $enabled,
            'url' => $enabled ? $url : null,
            'reachable' => $reachable,
            'error' => $error,
            'skill_count' => Skill::where('type', SkillType::Browser->value)->count(),
            'note' => $enabled
                ? ($reachable ? 'Browserless is running and reachable.' : 'Browserless is not reachable. Start with: docker compose --profile browser up -d')
                : 'Browser Skill is disabled. Set BROWSER_SKILL_ENABLED=true to enable.',
        ]));
    }
}
