<?php

namespace App\Mcp\Tools\Compact;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base class for compact/consolidated MCP tools.
 *
 * Each compact tool maps an "action" parameter to an original tool class,
 * delegating handle() calls with zero logic duplication. Schemas are
 * auto-merged from all child tools so clients discover every parameter.
 */
abstract class CompactTool extends Tool
{
    /**
     * Map of action name => original Tool class.
     *
     * @return array<string, class-string<Tool>>
     */
    abstract protected function toolMap(): array;

    public function schema(JsonSchema $schema): array
    {
        $actions = array_keys($this->toolMap());

        $merged = [
            'action' => $schema->string()
                ->description('Action to perform: '.implode(', ', $actions))
                ->enum($actions)
                ->required(),
        ];

        // Auto-merge schemas from all child tools so clients discover every parameter.
        foreach ($this->toolMap() as $toolClass) {
            try {
                $childSchema = app($toolClass)->schema($schema);

                foreach ($childSchema as $key => $value) {
                    if (! isset($merged[$key])) {
                        $merged[$key] = $value;
                    }
                }
            } catch (\Throwable) {
                // Skip tools that can't be instantiated at schema time.
            }
        }

        return $merged;
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');

        if (! $action) {
            $actions = array_keys($this->toolMap());

            return Response::error(
                "Missing required parameter 'action'. Valid actions: ".implode(', ', $actions),
            );
        }

        $map = $this->toolMap();

        if (! isset($map[$action])) {
            return Response::error(
                "Unknown action '{$action}'. Valid actions: ".implode(', ', array_keys($map)),
            );
        }

        return app($map[$action])->handle($request);
    }
}
