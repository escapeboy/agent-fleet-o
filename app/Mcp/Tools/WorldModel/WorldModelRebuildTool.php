<?php

namespace App\Mcp\Tools\WorldModel;

use App\Domain\WorldModel\Jobs\BuildWorldModelDigestJob;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class WorldModelRebuildTool extends Tool
{
    protected string $name = 'world_model_rebuild';

    protected string $description = 'Regenerate the team world-model digest immediately. Dispatches a queue job; call `world_model_get` afterwards to read the refreshed digest.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = auth()->user();
        if (! $user || ! $user->current_team_id) {
            return Response::error('Authentication or team context missing');
        }

        BuildWorldModelDigestJob::dispatch($user->current_team_id);

        return Response::text(json_encode([
            'team_id' => $user->current_team_id,
            'status' => 'queued',
            'message' => 'World-model rebuild queued. Expect completion within ~30s.',
        ]));
    }
}
