<?php

namespace App\Mcp\Resources;

use App\Mcp\McpAppResource;
use Laravel\Mcp\Response;

class AgentMonitorResource extends McpAppResource
{
    protected string $name = 'fleetq-agent-monitor';

    protected string $title = 'Agent Monitor';

    protected string $description = 'Interactive agent monitor with status, runtime stats, and lifecycle controls (Enable/Disable, Health Check).';

    protected string $uri = 'ui://fleetq/agent-monitor';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('views/mcp-apps/agent-monitor.html')));
    }
}
