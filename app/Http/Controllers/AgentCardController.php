<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Serves the A2A (Agent-to-Agent) Agent Card for external agent discovery.
 *
 * @see https://a2a-protocol.org/latest/topics/agent-discovery/
 */
class AgentCardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => 'FleetQ',
            'description' => 'AI Agent Mission Control — manage experiments, workflows, crews, approvals, and full agent lifecycle.',
            'url' => config('app.url').'/mcp',
            'version' => config('app.version', '1.0.0'),
            'authentication' => [
                'schemes' => ['Bearer'],
            ],
            'skills' => [
                [
                    'id' => 'run_experiment',
                    'name' => 'Run Experiment',
                    'description' => 'Creates and executes an AI experiment through the full 20-state pipeline',
                    'inputModes' => ['text'],
                    'outputModes' => ['text', 'structured'],
                    'examples' => ['Run a content scoring experiment for signal #abc123'],
                ],
                [
                    'id' => 'manage_workflow',
                    'name' => 'Manage Workflow',
                    'description' => 'Create, edit, validate, and execute visual DAG workflows with 8 node types',
                    'inputModes' => ['text'],
                    'outputModes' => ['text', 'structured'],
                    'examples' => ['Create a workflow that scores a signal and sends the result via email'],
                ],
                [
                    'id' => 'coordinate_crew',
                    'name' => 'Coordinate Crew',
                    'description' => 'Orchestrate multi-agent crews for complex tasks using hierarchical or sequential processes',
                    'inputModes' => ['text'],
                    'outputModes' => ['text', 'structured'],
                    'examples' => ['Execute a research crew to analyse competitor pricing'],
                ],
                [
                    'id' => 'manage_approvals',
                    'name' => 'Manage Approvals',
                    'description' => 'Review, approve, reject, and complete human-in-the-loop approval requests and human tasks',
                    'inputModes' => ['text'],
                    'outputModes' => ['text', 'structured'],
                    'examples' => ['List pending approvals and approve the content review for experiment #xyz'],
                ],
                [
                    'id' => 'query_platform',
                    'name' => 'Query Platform',
                    'description' => 'Read agents, skills, tools, credentials, signals, artifacts, budget, and audit logs',
                    'inputModes' => ['text'],
                    'outputModes' => ['text', 'structured'],
                    'examples' => ['What is the current budget remaining for my team?'],
                ],
            ],
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
                'stateTransitionHistory' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
