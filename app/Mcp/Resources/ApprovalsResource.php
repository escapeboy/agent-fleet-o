<?php

namespace App\Mcp\Resources;

use App\Mcp\McpAppResource;
use Laravel\Mcp\Response;

/**
 * MCP App resource — interactive approval inbox rendered in MCP Apps-capable hosts.
 *
 * The HTML app (resources/views/mcp-apps/approvals.html) is a self-contained
 * vanilla JS application that:
 *  - Connects to the host via postMessage JSON-RPC (MCP Apps protocol)
 *  - Renders pending approvals with Approve / Reject / Escalate buttons
 *  - Calls approval_approve / approval_reject / approval_escalate MCP tools directly
 *  - Updates the model context after each user action
 */
class ApprovalsResource extends McpAppResource
{
    protected string $name = 'fleetq-approvals';

    protected string $title = 'Approval Inbox';

    protected string $description = 'Interactive approval inbox with one-click Approve / Reject / Escalate actions.';

    protected string $uri = 'ui://fleetq/approvals';

    public function handle(): Response
    {
        $html = file_get_contents(resource_path('views/mcp-apps/approvals.html'));

        return Response::text($html);
    }
}
