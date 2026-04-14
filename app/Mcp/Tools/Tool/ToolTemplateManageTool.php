<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\DeployToolTemplateAction;
use App\Domain\Tool\Models\ToolTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

/**
 * MCP tool for browsing and deploying GPU tool templates.
 *
 * Actions:
 *   list   — List all available GPU tool templates
 *   get    — Get details of a specific template
 *   deploy — Deploy a template as a new tool for the team
 */
#[IsDestructive]
#[AssistantTool('write')]
class ToolTemplateManageTool extends Tool
{
    protected string $name = 'tool_template_manage';

    protected string $description = 'Browse and deploy pre-configured GPU tool templates (OCR, STT, TTS, image generation). Actions: list, get, deploy.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | get | deploy')
                ->required(),
            'slug' => $schema->string()
                ->description('Template slug (for get/deploy)'),
            'category' => $schema->string()
                ->description('Filter by category: ocr, stt, tts, image_generation, video_generation, embedding, code_execution'),
            'provider' => $schema->string()
                ->description('Override compute provider slug for deploy (default: template default)'),
            'endpoint_id' => $schema->string()
                ->description('Existing endpoint ID to connect (for deploy)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');

        return match ($action) {
            'list' => $this->listTemplates($request),
            'get' => $this->getTemplate($request),
            'deploy' => $this->deployTemplate($request),
            default => Response::error("Unknown action: {$action}. Valid: list, get, deploy"),
        };
    }

    private function listTemplates(Request $request): Response
    {
        $query = ToolTemplate::active()->orderBy('sort_order');

        $category = $request->get('category');
        if ($category) {
            $query->where('category', $category);
        }

        $templates = $query->get()->map(fn (ToolTemplate $t) => [
            'slug' => $t->slug,
            'name' => $t->name,
            'category' => $t->category->value,
            'description' => $t->description,
            'provider' => $t->provider,
            'estimated_gpu' => $t->estimated_gpu,
            'estimated_cost' => $t->estimatedCostDisplay(),
            'license' => $t->license,
            'is_featured' => $t->is_featured,
        ]);

        return Response::text(json_encode(['templates' => $templates, 'count' => $templates->count()]));
    }

    private function getTemplate(Request $request): Response
    {
        $slug = $request->get('slug');

        if (! $slug) {
            return Response::error('slug is required for get');
        }

        $template = ToolTemplate::where('slug', $slug)->first();

        if (! $template) {
            return Response::error("Template '{$slug}' not found.");
        }

        return Response::text(json_encode([
            'slug' => $template->slug,
            'name' => $template->name,
            'category' => $template->category->value,
            'description' => $template->description,
            'provider' => $template->provider,
            'docker_image' => $template->docker_image,
            'model_id' => $template->model_id,
            'estimated_gpu' => $template->estimated_gpu,
            'estimated_cost' => $template->estimatedCostDisplay(),
            'deploy_config' => $template->deploy_config,
            'input_schema' => $template->default_input_schema,
            'output_schema' => $template->default_output_schema,
            'tool_definitions' => $template->tool_definitions,
            'source_url' => $template->source_url,
            'license' => $template->license,
        ]));
    }

    private function deployTemplate(Request $request): Response
    {
        $slug = $request->get('slug');

        if (! $slug) {
            return Response::error('slug is required for deploy');
        }

        $template = ToolTemplate::where('slug', $slug)->first();

        if (! $template) {
            return Response::error("Template '{$slug}' not found.");
        }

        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('Team context required to deploy a template.');
        }

        $tool = app(DeployToolTemplateAction::class)->execute(
            teamId: $teamId,
            template: $template,
            provider: $request->get('provider'),
            endpointId: $request->get('endpoint_id'),
        );

        return Response::text(json_encode([
            'status' => 'deployed',
            'tool_id' => $tool->id,
            'tool_name' => $tool->name,
            'tool_status' => $tool->status->value,
            'provider' => $tool->transport_config['provider'] ?? $template->provider,
            'message' => $tool->status->value === 'active'
                ? "Tool '{$tool->name}' deployed and active."
                : "Tool '{$tool->name}' created. Set endpoint_id in tool settings to activate.",
        ]));
    }
}
