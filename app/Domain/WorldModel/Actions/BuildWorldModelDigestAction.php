<?php

namespace App\Domain\WorldModel\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\WorldModel\Models\TeamWorldModel;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Log;

final class BuildWorldModelDigestAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function execute(Team $team, int $windowDays = 14): TeamWorldModel
    {
        $stats = [
            'window_days' => $windowDays,
            'signal_count' => 0,
            'experiment_count' => 0,
            'memory_count' => 0,
        ];

        $since = now()->subDays($windowDays);

        $signals = Signal::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'source_type', 'payload', 'created_at']);
        $stats['signal_count'] = $signals->count();

        $experiments = Experiment::query()
            ->where('team_id', $team->id)
            ->where('updated_at', '>=', $since)
            ->whereIn('status', [ExperimentStatus::Completed, ExperimentStatus::Executing])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'title', 'thesis', 'status', 'track']);
        $stats['experiment_count'] = $experiments->count();

        $memories = Memory::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'content']);
        $stats['memory_count'] = $memories->count();

        $total = $signals->count() + $experiments->count() + $memories->count();
        if ($total === 0) {
            $record = $this->persist($team, digest: null, provider: null, model: null, stats: array_merge($stats, [
                'skipped' => 'no data in window',
            ]));

            return $record;
        }

        $systemPrompt = <<<'PROMPT'
        You are a digest generator that compresses recent team activity into a short
        "world model" briefing for downstream AI agents. Keep it under 250 words.

        Output EXACTLY these sections, each starting with a heading:
        ## Current focus
        ## Recent signals
        ## Recent outcomes
        ## Watchlist / risks

        Rules:
        - Summarise concrete facts; don't invent.
        - Name specific agents, projects, or experiment titles only when they clarify
          the picture.
        - No bullet points in the "Current focus" section — write a single sentence.
        - If a section has no data in the inputs, write "None observed in the window."
        PROMPT;

        $userPrompt = sprintf(
            "Window: last %d days.\n\n### Signals (%d)\n%s\n\n### Experiments (%d)\n%s\n\n### Memories (%d)\n%s",
            $windowDays,
            $signals->count(),
            $this->formatSignals($signals),
            $experiments->count(),
            $this->formatExperiments($experiments),
            $memories->count(),
            $this->formatMemories($memories),
        );

        $settings = $team->settings ?? [];
        $provider = ($settings['assistant_llm_provider'] ?? null)
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $model = ($settings['assistant_llm_model'] ?? null)
            ?? GlobalSetting::get('default_llm_model', 'claude-haiku-4-5-20251001');

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                maxTokens: 800,
                teamId: $team->id,
                purpose: 'world_model_digest',
                temperature: 0.2,
            ));

            return $this->persist($team, digest: trim($response->content), provider: $provider, model: $model, stats: $stats);
        } catch (\Throwable $e) {
            Log::warning('BuildWorldModelDigestAction: LLM call failed', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return $this->persist($team, digest: null, provider: $provider, model: $model, stats: array_merge($stats, ['error' => $e->getMessage()]));
        }
    }

    /**
     * @param  iterable<Signal>  $signals
     */
    private function formatSignals(iterable $signals): string
    {
        $lines = [];
        foreach ($signals as $signal) {
            $summary = mb_strimwidth(json_encode($signal->payload ?? []), 0, 120, '…');
            $lines[] = sprintf('- [%s] %s: %s', $signal->created_at?->toDateString() ?? 'n/a', $signal->source_type ?? 'unknown', $summary);
        }

        return $lines === [] ? '(no signals)' : implode("\n", $lines);
    }

    private function formatExperiments(iterable $experiments): string
    {
        $lines = [];
        foreach ($experiments as $exp) {
            $lines[] = sprintf('- %s | %s | %s | %s', $exp->status->value, $exp->track->value ?? '-', $exp->title, mb_strimwidth((string) $exp->thesis, 0, 100, '…'));
        }

        return $lines === [] ? '(no experiments)' : implode("\n", $lines);
    }

    private function formatMemories(iterable $memories): string
    {
        $lines = [];
        foreach ($memories as $memory) {
            $lines[] = '- '.mb_strimwidth(trim((string) $memory->content), 0, 140, '…');
        }

        return $lines === [] ? '(no memories)' : implode("\n", $lines);
    }

    private function persist(Team $team, ?string $digest, ?string $provider, ?string $model, array $stats): TeamWorldModel
    {
        $record = TeamWorldModel::withoutGlobalScopes()
            ->updateOrCreate(
                ['team_id' => $team->id],
                [
                    'digest' => $digest,
                    'provider' => $provider,
                    'model' => $model,
                    'stats' => $stats,
                    'generated_at' => now(),
                ]
            );

        return $record->refresh();
    }
}
