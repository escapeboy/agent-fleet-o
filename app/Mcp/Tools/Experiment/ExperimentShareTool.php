<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentShareTool extends Tool
{
    protected string $name = 'experiment_share';

    protected string $description = 'Manage public share links for an experiment. Actions: generate (create/reset token), revoke (disable sharing), get (read current config), update (modify share_config options like show_costs, expires_at).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'action' => $schema->string()
                ->description('Action: generate | revoke | get | update')
                ->enum(['generate', 'revoke', 'get', 'update'])
                ->required(),
            'show_costs' => $schema->boolean()
                ->description('Whether to show cost data in the public view (for update action)'),
            'show_stages' => $schema->boolean()
                ->description('Whether to show pipeline stages in the public view (for update action)'),
            'show_outputs' => $schema->boolean()
                ->description('Whether to show stage outputs in the public view (for update action)'),
            'expires_at' => $schema->string()
                ->description('ISO8601 expiry datetime after which the share link is invalid. Pass null to remove expiry. (for update action)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $experimentId = $request->get('experiment_id');
        $action = $request->get('action');

        if (! $experimentId) {
            return Response::error('experiment_id is required.');
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($experimentId);

        if (! $experiment) {
            return Response::error("Experiment {$experimentId} not found.");
        }

        return match ($action) {
            'generate' => $this->generate($experiment),
            'revoke' => $this->revoke($experiment),
            'get' => $this->get($experiment),
            'update' => $this->update($experiment, $request),
            default => Response::error("Unknown action: {$action}"),
        };
    }

    private function generate(Experiment $experiment): Response
    {
        $experiment->generateShareToken();
        $experiment->refresh();

        $shareUrl = url('/share/'.$experiment->share_token);

        return Response::text(json_encode([
            'share_enabled' => true,
            'share_url' => $shareUrl,
            'share_config' => $experiment->share_config,
        ]));
    }

    private function revoke(Experiment $experiment): Response
    {
        $experiment->update(['share_enabled' => false]);

        return Response::text(json_encode([
            'share_enabled' => false,
            'message' => 'Share link revoked. The token is preserved but sharing is disabled.',
        ]));
    }

    private function get(Experiment $experiment): Response
    {
        $hasToken = ! empty($experiment->share_token);
        $shareUrl = $hasToken && $experiment->share_enabled
            ? url('/share/'.$experiment->share_token)
            : null;

        return Response::text(json_encode([
            'share_enabled' => (bool) $experiment->share_enabled,
            'share_url' => $shareUrl,
            'share_config' => $experiment->share_config ?? [],
            'is_expired' => $hasToken ? $experiment->isShareExpired() : false,
        ]));
    }

    private function update(Experiment $experiment, Request $request): Response
    {
        $current = $experiment->share_config ?? [];

        $updates = array_filter([
            'show_costs' => $request->get('show_costs'),
            'show_stages' => $request->get('show_stages'),
            'show_outputs' => $request->get('show_outputs'),
        ], fn ($v) => $v !== null);

        // Handle expires_at separately (can be null to clear)
        if ($request->has('expires_at')) {
            $updates['expires_at'] = $request->get('expires_at');
        }

        $merged = array_merge($current, $updates);
        $experiment->update(['share_config' => $merged]);

        return Response::text(json_encode([
            'share_enabled' => (bool) $experiment->share_enabled,
            'share_config' => $merged,
        ]));
    }
}
