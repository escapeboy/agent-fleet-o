<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Workflow\Services\WorkflowNodeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * @tags Workflows
 */
class WorkflowPluginNodesController extends Controller
{
    public function index(WorkflowNodeRegistry $registry): JsonResponse
    {
        $nodes = collect($registry->definitions())->map(fn ($def) => [
            'type' => $def->type,
            'label' => $def->label,
            'icon' => $def->icon,
            'category' => $def->category,
            'color' => $def->color,
            'default_config' => $def->defaultConfig,
            'config_schema' => $def->configSchema,
        ])->values();

        return response()->json(['nodes' => $nodes]);
    }
}
