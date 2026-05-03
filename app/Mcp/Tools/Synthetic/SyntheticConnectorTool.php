<?php

namespace App\Mcp\Tools\Synthetic;

use App\Mcp\Contracts\AutoRegistersAsMcpTool;
use App\Mcp\ErrorClassifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Abstract base for synthesized per-connector MCP tools.
 *
 * Generated subclasses (one per opted-in connector) live in
 * bootstrap/cache/synthetic-mcp-tools/ and only override connector() to return
 * the bound connector instance. See ConnectorMcpRegistrar.
 */
abstract class SyntheticConnectorTool extends Tool
{
    /** Implemented by per-connector generated subclass. */
    abstract protected function connector(): AutoRegistersAsMcpTool;

    public function name(): string
    {
        return $this->connector()->mcpName();
    }

    public function description(): string
    {
        return $this->connector()->mcpDescription();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->connector()->mcpInputSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'no_team_resolved']));
        }

        try {
            $result = $this->connector()->mcpInvoke($request->all(), (string) $teamId);

            return Response::text(json_encode($result, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            Log::warning('SyntheticConnectorTool: connector invocation failed', [
                'tool' => $this->name(),
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            $classifier = app()->bound(ErrorClassifier::class) ? app(ErrorClassifier::class) : null;
            $code = $classifier ? $classifier->classify($e) : 'INTERNAL';

            return Response::text(json_encode([
                'error' => $e->getMessage(),
                'code' => is_object($code) && method_exists($code, 'value') ? $code->value : (string) $code,
            ]));
        }
    }
}
