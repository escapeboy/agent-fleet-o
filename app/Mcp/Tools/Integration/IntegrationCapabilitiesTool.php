<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class IntegrationCapabilitiesTool extends Tool
{
    protected string $name = 'integration_capabilities';

    protected string $description = 'List available actions and triggers for a connected integration driver.';

    public function __construct(private readonly IntegrationManager $manager) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('Integration UUID — use to query capabilities of a connected integration'),
            'driver' => $schema->string()
                ->description('Driver slug — use to query capabilities without connecting first'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        $integrationId = $request->get('integration_id');
        $driverSlug    = $request->get('driver');

        if ($integrationId) {
            $integration = Integration::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', $integrationId)
                ->first();

            if (! $integration) {
                return Response::error('Integration not found.');
            }

            $driverSlug = $integration->driver;
        }

        if (! $driverSlug) {
            return Response::error('Provide integration_id or driver.');
        }

        try {
            $driver = $this->manager->driver($driverSlug);

            return Response::text(json_encode([
                'driver'   => $driverSlug,
                'actions'  => array_map(fn ($a) => [
                    'key'          => $a->key,
                    'label'        => $a->label,
                    'description'  => $a->description,
                    'input_schema' => $a->inputSchema,
                ], $driver->actions()),
                'triggers' => array_map(fn ($t) => [
                    'key'         => $t->key,
                    'label'       => $t->label,
                    'description' => $t->description,
                ], $driver->triggers()),
            ]));
        } catch (\Throwable $e) {
            return Response::error('Driver not found: '.$e->getMessage());
        }
    }
}
