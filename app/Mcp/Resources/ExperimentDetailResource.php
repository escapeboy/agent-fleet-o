<?php

namespace App\Mcp\Resources;

use App\Mcp\McpAppResource;
use Laravel\Mcp\Response;

class ExperimentDetailResource extends McpAppResource
{
    protected string $name = 'fleetq-experiment-detail';

    protected string $title = 'Experiment Detail';

    protected string $description = 'Interactive experiment dashboard with stage timeline, budget meter, and lifecycle actions (Kill, Pause, Resume, Retry).';

    protected string $uri = 'ui://fleetq/experiment-detail';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('views/mcp-apps/experiment-detail.html')));
    }
}
