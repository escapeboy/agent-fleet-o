<?php

namespace App\Mcp\Tools\Phoenix;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\Services\TranscriptIngestor;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool: replay a local CLI agent's session transcript into Phoenix as a
 * trace. Surfaces the per-turn tool calls + token usage that the cloud gateway
 * never sees for bridge / claude-code-vps runs.
 *
 * Accepts transcript CONTENT only — never a server-side file path — so an
 * agent cannot coax the platform into reading arbitrary files. The caller
 * (bridge, or a human) reads the JSONL and posts it.
 *
 * No-op unless both `llmops.transcript_ingest.enabled` and `llmops.phoenix.enabled`
 * are set; emitting telemetry never fails the call.
 */
#[IsDestructive]
#[AssistantTool('write')]
class LocalAgentTranscriptIngestTool extends Tool
{
    protected string $name = 'local_agent_transcript_ingest';

    protected string $description = 'Replay a local CLI agent session transcript (e.g. Claude Code JSONL) into '
        .'Phoenix as a trace, so its per-turn tool calls and token usage become observable. Pass the transcript '
        .'content directly. No-op when transcript ingestion or Phoenix export is disabled.';

    public function __construct(private readonly TranscriptIngestor $ingestor) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'transcript' => $schema->string()
                ->description('Raw transcript content (line-delimited JSON for Claude Code).')
                ->required(),
            'source' => $schema->string()
                ->description('Runtime that produced the transcript. Default "claude-code".')
                ->default('claude-code'),
            'agent_id' => $schema->string()
                ->description('Optional FleetQ agent UUID to attribute the trace to.'),
            'experiment_id' => $schema->string()
                ->description('Optional experiment UUID to attribute the trace to.'),
            'mask' => $schema->boolean()
                ->description('Redact message + tool-input content before export. Defaults to the Phoenix mask_content setting.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'transcript' => 'required|string|max:10000000',
            'source' => 'nullable|string|max:64',
            'agent_id' => 'nullable|string|max:64',
            'experiment_id' => 'nullable|string|max:64',
            'mask' => 'nullable|boolean',
        ]);

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;

        // Authorize the attribution ids against the caller's team so a caller
        // cannot stamp a trace with another team's agent/experiment id.
        $agentId = $validated['agent_id'] ?? null;
        if ($agentId !== null && ! $this->belongsToTeam(Agent::class, $agentId, $teamId)) {
            return Response::error('agent_id does not belong to your team');
        }

        $experimentId = $validated['experiment_id'] ?? null;
        if ($experimentId !== null && ! $this->belongsToTeam(Experiment::class, $experimentId, $teamId)) {
            return Response::error('experiment_id does not belong to your team');
        }

        $context = [
            'source' => $validated['source'] ?? 'claude-code',
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
        ];

        if (array_key_exists('mask', $validated) && $validated['mask'] !== null) {
            $context['mask'] = (bool) $validated['mask'];
        }

        $result = $this->ingestor->ingest($validated['transcript'], $context);

        return Response::text((string) json_encode($result));
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function belongsToTeam(string $model, string $id, ?string $teamId): bool
    {
        if ($teamId === null) {
            return false;
        }

        return $model::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereKey($id)
            ->exists();
    }
}
