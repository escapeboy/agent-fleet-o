<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Serves the Agent Skills discovery index and the SKILL.md artifacts it
 * points at.
 *
 * Artifacts live in resources/agent-skills/{name}/SKILL.md and are templated
 * with {{APP_URL}} so a self-hosted install advertises its own base URL. The
 * digest is computed over the rendered body, so it always matches what the
 * artifact endpoint returns for this host.
 *
 * @see https://github.com/cloudflare/agent-skills-discovery-rfc
 */
class AgentSkillsController extends Controller
{
    private const SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    /** @var array<string, string> */
    private const SKILLS = [
        'fleetq-connect' => 'Connect an agent to FleetQ over MCP or the REST API, including obtaining credentials via dynamic client registration.',
        'fleetq-run-experiment' => 'Create, run, monitor, and recover a FleetQ experiment through its 20-state pipeline.',
        'fleetq-manage-workflow' => 'Build, validate, cost, and execute FleetQ visual DAG workflows.',
    ];

    public function index(): JsonResponse
    {
        $skills = [];

        foreach (self::SKILLS as $name => $description) {
            $body = $this->render($name);

            if ($body === null) {
                continue;
            }

            $skills[] = [
                'name' => $name,
                'type' => 'skill-md',
                'description' => $description,
                'url' => url('/.well-known/agent-skills/'.$name.'/SKILL.md'),
                'digest' => 'sha256:'.hash('sha256', $body),
            ];
        }

        return response()->json([
            '$schema' => self::SCHEMA,
            'skills' => $skills,
        ]);
    }

    public function show(string $name): Response
    {
        abort_unless(array_key_exists($name, self::SKILLS), 404);

        $body = $this->render($name);

        abort_if($body === null, 404);

        return response($body, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function render(string $name): ?string
    {
        $path = resource_path('agent-skills/'.$name.'/SKILL.md');

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return str_replace('{{APP_URL}}', rtrim(config('app.url'), '/'), $contents);
    }
}
