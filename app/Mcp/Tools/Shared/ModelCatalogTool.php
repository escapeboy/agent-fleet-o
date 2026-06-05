<?php

namespace App\Mcp\Tools\Shared;

use App\Infrastructure\AI\Services\ManagedModelDiscovery;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Inspect and refresh the live model catalog for managed multi-model providers
 * (OpenRouter, …). Actions:
 *   - list:    return the live catalog for a provider (cached).
 *   - refresh: bust the cache and re-sync catalogs + pricing (mutates state).
 */
#[IsDestructive]
#[AssistantTool('read')]
class ModelCatalogTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'model_catalog';

    protected string $description = 'Inspect or refresh the live model catalog for managed multi-model providers '
        .'(OpenRouter, etc.). Actions: list (provider required), refresh (busts cache + re-syncs pricing).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | refresh')
                ->required(),
            'provider' => $schema->string()
                ->description('Provider key (e.g. openrouter). Required for list; optional filter for refresh.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! config('model_catalog.enabled')) {
            return $this->failedPreconditionError('Dynamic model catalog sync is disabled (MANAGED_MODEL_CATALOG_SYNC=false).');
        }

        return match ($request->get('action')) {
            'list' => $this->handleList($request),
            'refresh' => $this->handleRefresh($request),
            default => $this->invalidArgumentError("Unknown action '{$request->get('action')}'. Valid: list, refresh"),
        };
    }

    private function handleList(Request $request): Response
    {
        $provider = $request->get('provider');
        if (! $provider || empty(config("llm_providers.{$provider}.dynamic_catalog"))) {
            return $this->invalidArgumentError("provider must be a managed provider with a dynamic catalog (e.g. 'openrouter').");
        }

        $entries = app(ManagedModelDiscovery::class)->discover($provider);

        return Response::text(json_encode([
            'provider' => $provider,
            'count' => count($entries),
            'models' => array_map(fn ($e) => [
                'id' => $e->id,
                'label' => $e->label,
                'input_usd_per_mtok' => $e->inputUsdPerMtok,
                'output_usd_per_mtok' => $e->outputUsdPerMtok,
                'context' => $e->context,
                'priced' => $e->priced(),
            ], $entries),
        ]));
    }

    private function handleRefresh(Request $request): Response
    {
        // Authorization: gated by manage-team (base = always allow single-team
        // community edition; cloud = role-based owner/admin).
        if (auth()->check() && Gate::denies('manage-team')) {
            return $this->permissionDeniedError('Only team owners/admins may refresh the model catalog.');
        }

        $options = [];
        if ($request->get('provider')) {
            $options['--provider'] = $request->get('provider');
        }

        Artisan::call('models:sync-catalog', $options);

        return Response::text(json_encode([
            'status' => 'refreshed',
            'output' => trim(Artisan::output()),
        ]));
    }
}
