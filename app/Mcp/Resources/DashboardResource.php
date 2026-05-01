<?php

namespace App\Mcp\Resources;

use App\Mcp\McpAppResource;
use Laravel\Mcp\Response;

class DashboardResource extends McpAppResource
{
    protected string $name = 'fleetq-dashboard';

    protected string $title = 'FleetQ Dashboard';

    protected string $description = 'Live KPI dashboard showing experiment, agent, project, and bridge status at a glance.';

    protected string $uri = 'ui://fleetq/dashboard';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('views/mcp-apps/dashboard.html')));
    }
}
