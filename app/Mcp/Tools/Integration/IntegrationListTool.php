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
class IntegrationListTool extends Tool
{
    protected string $name = 'integration_list';

    protected string $description = 'List connected integrations and available drivers (GitHub, Slack, Notion, Linear, etc.).';

    public function __construct(private readonly IntegrationManager $manager) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'driver' => $schema->string()
                ->description('Filter by driver slug (e.g. github, slack, notion)'),
            'include_drivers' => $schema->boolean()
                ->description('Include list of available drivers (default false)')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('No team context.');
        }

        $query = Integration::withoutGlobalScopes()->where('team_id', $teamId);

        if ($driver = $request->get('driver')) {
            $query->where('driver', $driver);
        }

        $integrations = $query->get()->map(fn (Integration $i) => [
            'id'             => $i->id,
            'driver'         => $i->driver,
            'name'           => $i->name,
            'status'         => $i->status->value,
            'error_count'    => $i->error_count,
            'last_pinged_at' => $i->last_pinged_at?->toIso8601String(),
        ]);

        $payload = ['integrations' => $integrations, 'total' => $integrations->count()];

        if ($request->get('include_drivers')) {
            $payload['available_drivers'] = collect(config('integrations.drivers', []))
                ->map(fn ($c, $slug) => ['slug' => $slug, 'label' => $c['label'], 'auth' => $c['auth']])
                ->values();
        }

        return Response::text(json_encode($payload));
    }
}
