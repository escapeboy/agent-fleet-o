<?php

namespace App\Mcp\Tools\Boruna;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * List Boruna's 10 capability gates with descriptions and current policy defaults.
 */
#[IsReadOnly]
class BorunaCapabilityListTool extends McpTool
{
    protected string $name = 'boruna_capabilities';

    protected string $description = 'List all Boruna capability gates (net.fetch, fs.read, fs.write, db.query, time.now, random, ui.render, llm.call, actor.spawn, actor.send) with descriptions and usage examples. Optionally fetch live capability info from a running Boruna server.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'boruna_tool_id' => $schema->string()
                ->description('UUID of the mcp_stdio Tool pointing to the Boruna binary. If provided, fetches live capability info.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'boruna_tool_id' => 'nullable|uuid',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        // Try to fetch live capability list from the Boruna server
        if ($validated['boruna_tool_id'] ?? null) {
            $tool = Tool::where('id', $validated['boruna_tool_id'])
                ->where('team_id', $teamId)
                ->where('type', 'mcp_stdio')
                ->first();

            if ($tool) {
                try {
                    // boruna_capability_list (added v0.3.0) includes capability_set_hash
                    $output = app(McpStdioClient::class)->callTool($tool, 'boruna_capability_list', []);

                    return Response::text($output);
                } catch (\Throwable) {
                    // Fall through to static definition
                }
            }
        }

        // Static capability definitions (always available, no binary required)
        return Response::text(json_encode([
            'capabilities' => [
                [
                    'name' => 'net.fetch',
                    'description' => 'Make HTTP/HTTPS requests to external URLs',
                    'safe_default' => false,
                    'example' => 'let res = net.fetch("https://api.example.com/data");',
                ],
                [
                    'name' => 'fs.read',
                    'description' => 'Read files from the filesystem',
                    'safe_default' => false,
                    'example' => 'let content = fs.read("/data/input.txt");',
                ],
                [
                    'name' => 'fs.write',
                    'description' => 'Write files to the filesystem',
                    'safe_default' => false,
                    'example' => 'fs.write("/data/output.json", json_encode(result));',
                ],
                [
                    'name' => 'db.query',
                    'description' => 'Execute SQL queries against a configured database',
                    'safe_default' => false,
                    'example' => 'let rows = db.query("SELECT * FROM users LIMIT 10");',
                ],
                [
                    'name' => 'time.now',
                    'description' => 'Get the current timestamp (non-deterministic — use sparingly)',
                    'safe_default' => true,
                    'example' => 'let ts = time.now();',
                ],
                [
                    'name' => 'random',
                    'description' => 'Generate random numbers (non-deterministic — use sparingly)',
                    'safe_default' => false,
                    'example' => 'let n = random.int(1, 100);',
                ],
                [
                    'name' => 'ui.render',
                    'description' => 'Render UI components or HTML output',
                    'safe_default' => false,
                    'example' => 'ui.render("<h1>Hello</h1>");',
                ],
                [
                    'name' => 'llm.call',
                    'description' => 'Call an LLM provider from within the Boruna script',
                    'safe_default' => false,
                    'example' => 'let answer = llm.call("anthropic/claude-sonnet-4-5", prompt);',
                ],
                [
                    'name' => 'actor.spawn',
                    'description' => 'Spawn a child actor for parallel execution',
                    'safe_default' => false,
                    'example' => 'let actor = actor.spawn(my_function, input);',
                ],
                [
                    'name' => 'actor.send',
                    'description' => 'Send a message to a running actor',
                    'safe_default' => false,
                    'example' => 'actor.send(actor_ref, message);',
                ],
            ],
            'policies' => [
                'allow-all' => 'All 10 capabilities are available. Use only for trusted scripts.',
                'deny-all' => 'All capabilities are blocked. Script can only perform pure computation.',
            ],
            'note' => 'Fine-grained per-capability policies are supported via MCP boruna_run policy_structured param. Strict validator available via boruna_policy_validate.',
        ]));
    }
}
