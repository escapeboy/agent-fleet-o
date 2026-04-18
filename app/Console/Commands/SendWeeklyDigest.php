<?php

namespace App\Console\Commands;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Notifications\WeeklyDigestNotification;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWeeklyDigest extends Command
{
    protected $signature = 'digest:send-weekly';

    protected $description = 'Send a weekly activity digest email to all team owners';

    public function handle(): int
    {
        $since = now()->subWeek();
        $sent = 0;

        Team::query()->with('owner')->chunk(100, function ($teams) use ($since, &$sent) {
            foreach ($teams as $team) {
                $owner = $team->owner;
                if (! $owner) {
                    continue;
                }

                $experimentsCreated = Experiment::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->count();

                $experimentsCompleted = Experiment::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('status', 'completed')
                    ->where('updated_at', '>=', $since)
                    ->count();

                $outboundSent = OutboundAction::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->count();

                $signalsIngested = Signal::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->count();

                $budgetSpent = CreditLedger::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->where('type', LedgerType::Deduction)
                    ->sum('amount');

                // Skip teams with zero activity
                if ($experimentsCreated + $outboundSent + $signalsIngested === 0) {
                    continue;
                }

                $executiveBrief = $this->generateBrief(
                    team: $team,
                    experimentsCreated: $experimentsCreated,
                    experimentsCompleted: $experimentsCompleted,
                    outboundSent: $outboundSent,
                    signalsIngested: $signalsIngested,
                    budgetSpentCredits: (int) abs($budgetSpent),
                );

                $owner->notify(new WeeklyDigestNotification(
                    team: $team,
                    experimentsCreated: $experimentsCreated,
                    experimentsCompleted: $experimentsCompleted,
                    outboundSent: $outboundSent,
                    signalsIngested: $signalsIngested,
                    budgetSpentCents: (int) abs($budgetSpent),
                    executiveBrief: $executiveBrief,
                ));

                $sent++;
            }
        });

        $this->info("Sent {$sent} weekly digest(s).");

        return self::SUCCESS;
    }

    private function generateBrief(
        Team $team,
        int $experimentsCreated,
        int $experimentsCompleted,
        int $outboundSent,
        int $signalsIngested,
        int $budgetSpentCredits,
    ): ?string {
        // Only generate briefs when there is meaningful activity
        if ($experimentsCreated + $signalsIngested + $outboundSent < 3) {
            return null;
        }

        $hasApiKey = ! empty(config('prism.providers.anthropic.api_key'))
            || ! empty(config('prism.providers.openai.api_key'))
            || ! empty(config('prism.providers.google.api_key'));

        if (! $hasApiKey) {
            return null;
        }

        $budgetUsd = number_format($budgetSpentCredits / 1000, 2);
        $successRate = $experimentsCreated > 0
            ? round(($experimentsCompleted / $experimentsCreated) * 100)
            : 0;

        // Gather recent completed experiment titles for context.
        // Titles are user-controlled — strip anything that could be used for prompt injection
        // by keeping only printable ASCII and collapsing whitespace.
        $recentTitles = Experiment::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('status', 'completed')
            ->where('updated_at', '>=', now()->subWeek())
            ->limit(5)
            ->pluck('title')
            ->map(fn (string $t) => preg_replace('/[^\x20-\x7E]/', '', mb_substr($t, 0, 60)))
            ->filter()
            ->implode(' | ');

        $recentLine = $recentTitles ? "- Recent completed work: {$recentTitles}" : '';

        $prompt = <<<TEXT
Write a concise 2-3 sentence executive brief for a team's weekly AI platform activity.
Tone: professional, data-driven, forward-looking. No bullet points — flowing prose only.

Stats:
- Team: {$team->name}
- Experiments created: {$experimentsCreated} ({$experimentsCompleted} completed, {$successRate}% success rate)
- Signals ingested: {$signalsIngested}
- Outbound messages sent: {$outboundSent}
- AI spend: \${$budgetUsd}
{$recentLine}

Write the executive brief now.
TEXT;

        try {
            $gateway = app(AiGatewayInterface::class);
            $response = $gateway->complete(new AiRequestDTO(
                provider: ! empty(config('prism.providers.anthropic.api_key')) ? 'anthropic' : (! empty(config('prism.providers.openai.api_key')) ? 'openai' : 'google'),
                model: ! empty(config('prism.providers.anthropic.api_key')) ? 'claude-haiku-4-5-20251001' : (! empty(config('prism.providers.openai.api_key')) ? 'gpt-4o-mini' : 'gemini-2.5-flash'),
                systemPrompt: 'You are an executive communications assistant. Be concise and insightful.',
                userPrompt: $prompt,
                maxTokens: 200,
                teamId: $team->id,
                purpose: 'weekly_brief',
                temperature: 0.6,
            ));

            return trim($response->content ?? '');
        } catch (\Throwable $e) {
            Log::warning('SendWeeklyDigest: brief generation failed', ['team_id' => $team->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
