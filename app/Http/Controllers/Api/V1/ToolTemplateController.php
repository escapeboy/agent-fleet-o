<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tool\Actions\DeployToolTemplateAction;
use App\Domain\Tool\Models\ToolTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Tool Templates
 */
class ToolTemplateController extends Controller
{
    /**
     * List GPU tool templates.
     *
     * Browse available pre-configured GPU tool templates, filterable by category.
     */
    public function index(Request $request): JsonResponse
    {
        $templates = QueryBuilder::for(ToolTemplate::class)
            ->active()
            ->allowedFilters([
                AllowedFilter::exact('category'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['name', 'sort_order', 'created_at'])
            ->defaultSort('sort_order')
            ->cursorPaginate(min((int) $request->input('per_page', 24), 100));

        return response()->json([
            'data' => $templates->map(fn (ToolTemplate $t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'name' => $t->name,
                'category' => $t->category->value,
                'description' => $t->description,
                'icon' => $t->icon,
                'provider' => $t->provider,
                'estimated_gpu' => $t->estimated_gpu,
                'estimated_cost' => $t->estimatedCostDisplay(),
                'license' => $t->license,
                'source_url' => $t->source_url,
                'is_featured' => $t->is_featured,
            ]),
            'meta' => [
                'next_cursor' => $templates->nextCursor()?->encode(),
                'has_more' => $templates->hasMorePages(),
            ],
        ]);
    }

    /**
     * Get template details.
     *
     * Retrieve full details of a specific tool template including deploy configuration.
     */
    public function show(ToolTemplate $toolTemplate): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $toolTemplate->id,
                'slug' => $toolTemplate->slug,
                'name' => $toolTemplate->name,
                'category' => $toolTemplate->category->value,
                'description' => $toolTemplate->description,
                'icon' => $toolTemplate->icon,
                'provider' => $toolTemplate->provider,
                'docker_image' => $toolTemplate->docker_image,
                'model_id' => $toolTemplate->model_id,
                'estimated_gpu' => $toolTemplate->estimated_gpu,
                'estimated_cost' => $toolTemplate->estimatedCostDisplay(),
                'estimated_cost_per_hour' => $toolTemplate->estimated_cost_per_hour,
                'license' => $toolTemplate->license,
                'source_url' => $toolTemplate->source_url,
                'is_featured' => $toolTemplate->is_featured,
                'input_schema' => $toolTemplate->input_schema,
                'output_schema' => $toolTemplate->output_schema,
                'tool_definitions' => $toolTemplate->tool_definitions,
            ],
        ]);
    }

    /**
     * Deploy a tool template.
     *
     * Create a new tool from a template with the specified compute provider and optional endpoint ID.
     */
    public function deploy(Request $request, ToolTemplate $toolTemplate): JsonResponse
    {
        $request->validate([
            'provider' => 'required|string|max:50',
            'endpoint_id' => 'nullable|string|max:255',
        ]);

        $tool = app(DeployToolTemplateAction::class)->execute(
            template: $toolTemplate,
            teamId: $request->user()->current_team_id,
            provider: $request->input('provider'),
            endpointId: $request->input('endpoint_id'),
        );

        return response()->json([
            'data' => [
                'id' => $tool->id,
                'name' => $tool->name,
                'slug' => $tool->slug,
                'status' => $tool->status->value,
                'type' => $tool->type->value,
            ],
            'message' => 'Tool deployed from template.',
        ], 201);
    }
}
