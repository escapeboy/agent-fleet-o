<?php

namespace App\Mcp\Services;

use App\Mcp\McpAppResource;
use App\Mcp\Resources\AgentMonitorResource;
use App\Mcp\Resources\ApprovalsResource;
use App\Mcp\Resources\CrewExecutionResource;
use App\Mcp\Resources\DashboardResource;
use App\Mcp\Resources\ExperimentDetailResource;
use App\Mcp\Resources\WorkflowDagResource;
use App\Mcp\Tools\Agent\AgentGetTool;
use App\Mcp\Tools\Agent\AgentHeartbeatRunNowTool;
use App\Mcp\Tools\Agent\AgentToggleStatusTool;
use App\Mcp\Tools\Approval\ApprovalListTool;
use App\Mcp\Tools\Crew\CrewExecutionPauseTool;
use App\Mcp\Tools\Crew\CrewExecutionResumeTool;
use App\Mcp\Tools\Crew\CrewExecutionStatusTool;
use App\Mcp\Tools\Experiment\ExperimentGetTool;
use App\Mcp\Tools\Experiment\ExperimentKillTool;
use App\Mcp\Tools\Experiment\ExperimentPauseTool;
use App\Mcp\Tools\Experiment\ExperimentResumeTool;
use App\Mcp\Tools\Experiment\ExperimentRetryTool;
use App\Mcp\Tools\System\DashboardKpisTool;
use App\Mcp\Tools\Workflow\WorkflowActivateTool;
use App\Mcp\Tools\Workflow\WorkflowDeactivateTool;
use App\Mcp\Tools\Workflow\WorkflowDuplicateTool;
use App\Mcp\Tools\Workflow\WorkflowGetTool;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;

/**
 * Central registry mapping MCP tool names ↔ MCP App resource URIs ↔ classes.
 *
 * Used by the AssistantPanel postMessage bridge to:
 *   1. Detect which tool calls in a conversation have an associated interactive UI.
 *   2. Fetch the HTML for a given ui:// URI (instantiates AppResource, calls handle()).
 *   3. Execute MCP tools on behalf of the sandboxed iframe (with auth + rate limiting).
 */
class McpAppRegistry
{
    /**
     * @var array<string, array{uri: string, resource: class-string<McpAppResource>, tool: class-string}>
     */
    private static array $map = [
        'experiment_get' => [
            'uri' => 'ui://fleetq/experiment-detail',
            'resource' => ExperimentDetailResource::class,
            'tool' => ExperimentGetTool::class,
        ],
        'agent_get' => [
            'uri' => 'ui://fleetq/agent-monitor',
            'resource' => AgentMonitorResource::class,
            'tool' => AgentGetTool::class,
        ],
        'workflow_get' => [
            'uri' => 'ui://fleetq/workflow-dag',
            'resource' => WorkflowDagResource::class,
            'tool' => WorkflowGetTool::class,
        ],
        'crew_execution_status' => [
            'uri' => 'ui://fleetq/crew-execution',
            'resource' => CrewExecutionResource::class,
            'tool' => CrewExecutionStatusTool::class,
        ],
        'system_dashboard_kpis' => [
            'uri' => 'ui://fleetq/dashboard',
            'resource' => DashboardResource::class,
            'tool' => DashboardKpisTool::class,
        ],
        'approval_list' => [
            'uri' => 'ui://fleetq/approvals',
            'resource' => ApprovalsResource::class,
            'tool' => ApprovalListTool::class,
        ],
    ];

    /**
     * All tools that can call write actions from within MCP App iframes.
     * This is a superset of the display tools above.
     *
     * @var array<string, class-string>
     */
    private static array $allowedCallableTools = [
        // Display (read) tools — also entry points for their respective apps
        'experiment_get' => ExperimentGetTool::class,
        'agent_get' => AgentGetTool::class,
        'workflow_get' => WorkflowGetTool::class,
        'crew_execution_status' => CrewExecutionStatusTool::class,
        'system_dashboard_kpis' => DashboardKpisTool::class,
        'approval_list' => ApprovalListTool::class,
        // Experiment write actions
        'experiment_pause' => ExperimentPauseTool::class,
        'experiment_resume' => ExperimentResumeTool::class,
        'experiment_retry' => ExperimentRetryTool::class,
        'experiment_kill' => ExperimentKillTool::class,
        // Agent write actions
        'agent_toggle_status' => AgentToggleStatusTool::class,
        'agent_heartbeat_run_now' => AgentHeartbeatRunNowTool::class,
        // Workflow write actions
        'workflow_activate' => WorkflowActivateTool::class,
        'workflow_deactivate' => WorkflowDeactivateTool::class,
        'workflow_duplicate' => WorkflowDuplicateTool::class,
        // Crew write actions
        'crew_execution_pause' => CrewExecutionPauseTool::class,
        'crew_execution_resume' => CrewExecutionResumeTool::class,
    ];

    public static function uriForTool(string $toolName): ?string
    {
        return self::$map[$toolName]['uri'] ?? null;
    }

    /**
     * Fetch and return the HTML for a given ui:// URI.
     * Returns null if the URI is not registered or the HTML file is missing.
     */
    public static function htmlForUri(string $uri): ?string
    {
        foreach (self::$map as $entry) {
            if ($entry['uri'] === $uri) {
                /** @var McpAppResource $resource */
                $resource = app($entry['resource']);
                $response = $resource->handle();

                return (string) $response->content();
            }
        }

        return null;
    }

    /**
     * Extract all MCP App URIs for a given set of tool calls stored in AssistantMessage.tool_calls.
     *
     * @param  array<int, array{toolName: string}>  $toolCalls
     * @return array<string, string> Map of toolName => uri
     */
    public static function extractUris(array $toolCalls): array
    {
        $uris = [];

        foreach ($toolCalls as $call) {
            $toolName = $call['toolName'] ?? '';
            if ($toolName !== '' && isset(self::$map[$toolName])) {
                $uris[$toolName] = self::$map[$toolName]['uri'];
            }
        }

        return $uris;
    }

    /**
     * Execute a tool on behalf of an MCP App iframe, with rate limiting and authorization.
     *
     * @param  array<string, mixed>  $params
     * @return array{content?: list<array{type: string, text: string}>, error?: string}
     */
    public static function callTool(string $toolName, array $params): array
    {
        $user = Auth::user();
        if (! $user) {
            return ['error' => 'Unauthenticated'];
        }

        // Rate limit: 30 calls per minute per user across all MCP App tool calls
        if (! RateLimiter::attempt("mcp-app-tool:{$user->id}", 30, fn () => null, 60)) {
            return ['error' => 'Rate limit exceeded. Please wait before making more requests.'];
        }

        // Only allow tools explicitly registered as callable from app iframes
        $toolClass = self::getAllowedToolClass($toolName);
        if ($toolClass === null) {
            return ['error' => "Tool '{$toolName}' is not available from MCP Apps."];
        }

        try {
            // Bind team ID so TeamScope and tools that use app('mcp.team_id') work correctly
            $teamId = $user->current_team_id;
            app()->instance('mcp.team_id', $teamId);

            $tool = app($toolClass);
            $request = new Request($params);
            $response = $tool->handle($request);

            $text = (string) $response->content();

            return [
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Register additional callable tools (e.g. write-action tools not in the display map).
     * Called at boot time to extend the callable set without modifying this file.
     *
     * @param  array<string, class-string>  $tools
     */
    public static function registerCallableTools(array $tools): void
    {
        self::$allowedCallableTools = array_merge(self::$allowedCallableTools, $tools);
    }

    private static function getAllowedToolClass(string $toolName): ?string
    {
        return self::$allowedCallableTools[$toolName] ?? null;
    }
}
