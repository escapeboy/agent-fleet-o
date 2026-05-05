<?php

namespace App\Mcp\Resources;

use App\Mcp\McpAppResource;
use Laravel\Mcp\Response;

class WorkflowDagResource extends McpAppResource
{
    protected string $name = 'fleetq-workflow-dag';

    protected string $title = 'Workflow DAG Viewer';

    protected string $description = 'Interactive workflow graph with node/edge visualization and lifecycle actions (Activate, Deactivate, Duplicate).';

    protected string $uri = 'ui://fleetq/workflow-dag';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('views/mcp-apps/workflow-dag.html')));
    }
}
