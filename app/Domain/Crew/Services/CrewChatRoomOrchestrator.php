<?php

namespace App\Domain\Crew\Services;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Actions\DispatchChatMessageAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewChatMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Manages chat room execution mode for crews.
 *
 * In chat room mode, all agents share a message bus and take turns contributing
 * to a collaborative discussion. The orchestrator manages rounds:
 *   1. Post the goal as a system message
 *   2. Each agent sees the full message history and contributes
 *   3. After each round, check for convergence
 *   4. Synthesize final output when converged or max rounds reached
 */
class CrewChatRoomOrchestrator
{
    private const MAX_ROUNDS = 5;

    private const MAX_HISTORY_TOKENS = 8000;

    public function __construct(
        private readonly ExecuteAgentAction $executeAgent,
    ) {}

    public function start(CrewExecution $execution): void
    {
        $crew = $execution->crew;
        $members = $crew->workerMembers()->with('agent')->get();

        if ($members->isEmpty()) {
            $execution->update([
                'status' => CrewExecutionStatus::Failed,
                'error_message' => 'No worker members for chat room.',
            ]);

            return;
        }

        // Post the goal as a system message
        CrewChatMessage::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => null,
            'agent_name' => 'System',
            'role' => 'system',
            'content' => "Discussion Goal: {$execution->goal}\n\nAll participants should contribute their perspective. Build on each other's ideas. Aim for consensus.",
            'round' => 0,
        ]);

        // Run rounds synchronously (this runs in a queue job)
        $maxRounds = $execution->config_snapshot['max_chat_rounds'] ?? self::MAX_ROUNDS;

        for ($round = 1; $round <= $maxRounds; $round++) {
            Log::info('CrewChatRoom: starting round', [
                'execution_id' => $execution->id,
                'round' => $round,
            ]);

            $this->executeRound($execution, $members, $round);

            if ($this->hasConverged($execution, $round)) {
                Log::info('CrewChatRoom: convergence detected', [
                    'execution_id' => $execution->id,
                    'round' => $round,
                ]);
                break;
            }
        }

        // Synthesize final output
        $this->synthesize($execution);
    }

    /**
     * Execute one round: each member contributes in order.
     *
     * @param  Collection<int, CrewMember>  $members
     */
    private function executeRound(CrewExecution $execution, Collection $members, int $round): void
    {
        foreach ($members as $member) {
            if ($member->isExternal()) {
                $this->executeExternalMemberRound($execution, $member, $round);

                continue;
            }

            $agent = $member->agent;
            if (! $agent || $agent->status->value !== 'active') {
                continue;
            }

            $history = $this->buildHistoryForAgent($execution, $agent);

            try {
                $result = $this->executeAgent->execute(
                    agent: $agent,
                    input: [
                        'task' => "You are participating in round {$round} of a group discussion.\n\n"
                            ."Previous messages:\n{$history}\n\n"
                            .'Contribute your perspective. Be specific and build on what others said. '
                            .'If you agree with the emerging consensus, say so explicitly.',
                    ],
                    teamId: $execution->team_id,
                    userId: $execution->crew->user_id ?? $execution->team_id,
                );

                $output = $result['output']['response'] ?? ($result['output']['text'] ?? json_encode($result['output']));

                CrewChatMessage::create([
                    'crew_execution_id' => $execution->id,
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'role' => 'assistant',
                    'content' => $output,
                    'round' => $round,
                    'metadata' => [
                        'cost_credits' => $result['execution']->cost_credits ?? 0,
                        'duration_ms' => $result['execution']->duration_ms ?? 0,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('CrewChatRoom: agent failed', [
                    'agent_id' => $agent->id,
                    'round' => $round,
                    'error' => $e->getMessage(),
                ]);

                CrewChatMessage::create([
                    'crew_execution_id' => $execution->id,
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'role' => 'assistant',
                    'content' => '[Agent encountered an error and could not contribute this round.]',
                    'round' => $round,
                    'metadata' => ['error' => $e->getMessage()],
                ]);
            }
        }
    }

    /**
     * Invite an external peer agent to contribute to this round via the Agent Chat Protocol.
     * The external agent receives the shared message history and returns a reply; we persist it
     * as a CrewChatMessage just like internal agent contributions.
     */
    private function executeExternalMemberRound(CrewExecution $execution, CrewMember $member, int $round): void
    {
        $externalAgent = $member->externalAgent;
        if ($externalAgent === null || ! $externalAgent->status->isCallable()) {
            return;
        }

        $history = $this->buildHistoryForExternal($execution);

        try {
            $result = app(DispatchChatMessageAction::class)->execute(
                externalAgent: $externalAgent,
                content: "You are participating in round {$round} of a group discussion.\n\n"
                    ."Previous messages:\n{$history}\n\n"
                    .'Contribute your perspective. Be specific and build on what others said. '
                    .'If you agree with the emerging consensus, say so explicitly.',
                sessionToken: 'crew-chatroom:'.$execution->id,
                from: 'fleetq:crew:'.$execution->id,
            );

            $content = (string) (
                $result['remote_response']['content']
                ?? $result['remote_response']['output']
                ?? '[External agent acknowledged but returned no content.]'
            );

            CrewChatMessage::create([
                'crew_execution_id' => $execution->id,
                'agent_id' => null,
                'agent_name' => $externalAgent->name,
                'role' => 'assistant',
                'content' => $content,
                'round' => $round,
                'metadata' => [
                    'external_agent_id' => $externalAgent->id,
                    'session_id' => $result['session_id'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('CrewChatRoom: external member failed', [
                'external_agent_id' => $externalAgent->id,
                'round' => $round,
                'error' => $e->getMessage(),
            ]);

            CrewChatMessage::create([
                'crew_execution_id' => $execution->id,
                'agent_id' => null,
                'agent_name' => $externalAgent->name,
                'role' => 'assistant',
                'content' => '[External agent encountered an error and could not contribute this round.]',
                'round' => $round,
                'metadata' => ['external_agent_id' => $externalAgent->id, 'error' => $e->getMessage()],
            ]);
        }
    }

    private function buildHistoryForExternal(CrewExecution $execution): string
    {
        $messages = CrewChatMessage::where('crew_execution_id', $execution->id)
            ->orderBy('round')
            ->orderBy('created_at')
            ->get();

        $lines = [];
        foreach ($messages as $msg) {
            $lines[] = '['.$msg->agent_name.']: '.$msg->content;
        }

        return implode("\n", $lines);
    }

    private function buildHistoryForAgent(CrewExecution $execution, Agent $agent): string
    {
        $messages = CrewChatMessage::where('crew_execution_id', $execution->id)
            ->orderBy('round')
            ->orderBy('created_at')
            ->get();

        $history = '';
        $tokenEstimate = 0;

        foreach ($messages->reverse() as $msg) {
            $line = "[{$msg->agent_name} (round {$msg->round})]: {$msg->content}\n";
            $lineTokens = (int) ceil(mb_strlen($line) / 4);

            if ($tokenEstimate + $lineTokens > self::MAX_HISTORY_TOKENS) {
                break;
            }

            $history = $line.$history;
            $tokenEstimate += $lineTokens;
        }

        return $history;
    }

    /**
     * Check if agents have converged — majority explicitly agree.
     */
    private function hasConverged(CrewExecution $execution, int $round): bool
    {
        if ($round < 2) {
            return false;
        }

        $roundMessages = CrewChatMessage::where('crew_execution_id', $execution->id)
            ->where('round', $round)
            ->where('role', 'assistant')
            ->get();

        if ($roundMessages->isEmpty()) {
            return false;
        }

        $agreementCount = $roundMessages->filter(function ($msg) {
            $content = mb_strtolower($msg->content);

            return str_contains($content, 'i agree')
                || str_contains($content, 'consensus')
                || str_contains($content, 'we all agree')
                || str_contains($content, 'aligned on');
        })->count();

        // Convergence: majority of participants agree
        return $agreementCount >= ceil($roundMessages->count() * 0.6);
    }

    private function synthesize(CrewExecution $execution): void
    {
        $messages = CrewChatMessage::where('crew_execution_id', $execution->id)
            ->where('role', 'assistant')
            ->orderBy('round')
            ->orderBy('created_at')
            ->get();

        $discussion = $messages->map(fn ($m) => "[{$m->agent_name} R{$m->round}]: ".mb_substr($m->content, 0, 500))
            ->implode("\n");

        $totalCost = $messages->sum(fn ($m) => $m->metadata['cost_credits'] ?? 0);

        try {
            $model = config('context_compaction.summarizer_model', 'anthropic/claude-haiku-4-5');
            [$provider, $modelName] = array_pad(explode('/', $model, 2), 2, 'claude-haiku-4-5');

            $response = Prism::text()
                ->using($provider, $modelName)
                ->withSystemPrompt('Synthesize a multi-agent discussion into a clear final answer. Capture the consensus, any dissenting views, and actionable conclusions.')
                ->withPrompt("Goal: {$execution->goal}\n\nDiscussion:\n{$discussion}\n\nProvide a structured synthesis with: 1) Consensus, 2) Key insights, 3) Actionable recommendations.")
                ->withMaxTokens(1024)
                ->generate();

            $synthesis = $response->text;
        } catch (\Throwable $e) {
            $synthesis = "Discussion completed ({$messages->count()} messages across {$messages->max('round')} rounds). See chat messages for details.";
        }

        $execution->update([
            'status' => CrewExecutionStatus::Completed,
            'final_output' => [
                'response' => $synthesis,
                'total_messages' => $messages->count(),
                'total_rounds' => $messages->max('round'),
                'participants' => $messages->pluck('agent_name')->unique()->values()->toArray(),
            ],
            'total_cost_credits' => $totalCost,
            'completed_at' => now(),
        ]);
    }
}
