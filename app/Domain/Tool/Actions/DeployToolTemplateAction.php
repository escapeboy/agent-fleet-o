<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolTemplate;
use App\Infrastructure\Compute\Services\ComputeCredentialResolver;
use Illuminate\Support\Str;

class DeployToolTemplateAction
{
    public function __construct(
        private readonly ComputeCredentialResolver $credentialResolver,
    ) {}

    public function execute(
        string $teamId,
        ToolTemplate $template,
        ?string $provider = null,
        ?string $endpointId = null,
    ): Tool {
        $provider = $provider ?? $template->provider;
        $deployConfig = $template->deploy_config;

        $transportConfig = [
            'provider' => $provider,
            'endpoint_id' => $endpointId, // null until actually provisioned
            'template_slug' => $template->slug,
            'docker_image' => $template->docker_image,
            'model_id' => $template->model_id,
            'deploy_config' => $deployConfig,
            'input_schema' => $template->default_input_schema,
            'output_schema' => $template->default_output_schema,
        ];

        return Tool::create([
            'team_id' => $teamId,
            'name' => $template->name,
            'slug' => Str::slug($template->name).'-'.Str::random(4),
            'description' => $template->description,
            'type' => ToolType::ComputeEndpoint,
            'status' => $endpointId ? ToolStatus::Active : ToolStatus::Disabled,
            'transport_config' => $transportConfig,
            'credentials' => [],
            'tool_definitions' => $template->tool_definitions,
            'settings' => [
                'timeout' => 120,
                'template_id' => $template->id,
                'estimated_cost_per_hour' => $template->estimated_cost_per_hour,
            ],
            'tags' => [$template->category->value, 'gpu', $provider],
        ]);
    }
}
