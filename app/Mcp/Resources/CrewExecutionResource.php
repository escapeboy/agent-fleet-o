<?php

namespace App\Mcp\Resources;

use App\Mcp\McpAppResource;
use Laravel\Mcp\Response;

class CrewExecutionResource extends McpAppResource
{
    protected string $name = 'fleetq-crew-execution';

    protected string $title = 'Crew Execution';

    protected string $description = 'Interactive crew execution viewer with task timeline, agent assignments, and status tracking.';

    protected string $uri = 'ui://fleetq/crew-execution';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('views/mcp-apps/crew-execution.html')));
    }
}
