<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProposeNewSkillFromExperimentAction
{
    public function execute(Experiment $experiment): ?Skill
    {
        $team = Team::withoutGlobalScopes()->find($experiment->team_id);
        $minStages = $team?->settings['auto_skill_propose_min_stages']
            ?? config('skills.auto_propose.min_stages', 5);

        $stages = $experiment->stages()
            ->where('status', StageStatus::Completed->value)
            ->orderBy('started_at')
            ->get();

        if ($stages->count() < $minStages) {
            return null;
        }

        // Daily cap per team to prevent cost abuse
        $dailyCap = $team?->settings['auto_skill_propose_daily_cap']
            ?? config('skills.auto_propose.daily_cap', 5);
        $todayCount = Skill::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->whereJsonContains('configuration->auto_generated', true)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($todayCount >= $dailyCap) {
            Log::debug('ProposeNewSkillFromExperimentAction: Daily cap reached', [
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'today_count' => $todayCount,
                'cap' => $dailyCap,
            ]);

            return null;
        }

        // Check for existing similar skill via name similarity
        if ($this->hasSimilarSkill($experiment)) {
            Log::debug('ProposeNewSkillFromExperimentAction: Similar skill already exists', [
                'experiment_id' => $experiment->id,
            ]);

            return null;
        }

        // Extract procedure from stage outputs
        $procedure = $stages->map(fn ($s, $i) => ($i + 1).'. ['.
            ($s->stage instanceof \BackedEnum ? $s->stage->value : $s->stage).'] '.
            Str::limit(
                is_array($s->output_snapshot) ? json_encode($s->output_snapshot) : (string) $s->output_snapshot,
                500,
                '...',
            ),
        )->implode("\n");

        // Synthesize skill prompt via cheap model
        $skillPrompt = $this->synthesizeSkillPrompt($experiment, $procedure);

        if (empty($skillPrompt)) {
            return null;
        }

        // Create draft skill
        $skill = app(CreateSkillAction::class)->execute(
            teamId: $experiment->team_id,
            name: 'Auto: '.Str::limit($experiment->title, 80),
            type: SkillType::Llm,
            description: "Auto-generated from experiment: {$experiment->title}",
            configuration: [
                'auto_generated' => true,
                'source_experiment_id' => $experiment->id,
                'stage_count' => $stages->count(),
                'generation_model' => 'anthropic/claude-haiku-4-5',
            ],
            systemPrompt: $skillPrompt,
        );

        // Force status to Draft (CreateSkillAction sets it to Draft by default)
        if ($skill->status !== SkillStatus::Draft) {
            $skill->update(['status' => SkillStatus::Draft]);
        }

        // Notify team
        try {
            app(NotificationService::class)->notifyTeam(
                teamId: $experiment->team_id,
                type: 'skill_auto_proposed',
                title: 'New Skill Auto-Proposed',
                body: "A new skill \"{$skill->name}\" was auto-created from experiment \"{$experiment->title}\". Review and activate it.",
                data: [
                    'skill_id' => $skill->id,
                    'experiment_id' => $experiment->id,
                ],
            );
        } catch (\Throwable $e) {
            Log::debug('ProposeNewSkillFromExperimentAction: Notification failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $skill;
    }

    private function hasSimilarSkill(Experiment $experiment): bool
    {
        $threshold = config('skills.auto_propose.similarity_threshold', 0.85);

        // First try exact name match
        $exists = Skill::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->where(function ($q) use ($experiment) {
                $q->where('name', 'LIKE', '%'.Str::limit($experiment->title, 50).'%')
                    ->orWhere('name', 'LIKE', 'Auto: '.Str::limit($experiment->title, 50).'%');
            })
            ->exists();

        if ($exists) {
            return true;
        }

        // Try pgvector semantic similarity if embeddings exist
        if (DB::getDriverName() === 'pgsql') {
            try {
                $similar = DB::select(
                    'SELECT COUNT(*) as cnt FROM skills
                     WHERE team_id = ?
                     AND embedding IS NOT NULL
                     AND 1 - (embedding <=> (SELECT embedding FROM experiments WHERE id = ?)) > ?',
                    [$experiment->team_id, $experiment->id, $threshold],
                );

                if (($similar[0]->cnt ?? 0) > 0) {
                    return true;
                }
            } catch (\Throwable) {
                // Embedding columns may not exist — fall through
            }
        }

        return false;
    }

    private function synthesizeSkillPrompt(Experiment $experiment, string $procedure): ?string
    {
        try {
            $response = app(AiGatewayInterface::class)->execute(new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                systemPrompt: 'You are a skill template generator. Given an experiment procedure, create a reusable skill prompt. Focus on the generalizable steps, not specific data. Output ONLY the skill prompt text, no explanation.',
                userMessage: "Experiment: {$experiment->title}\nThesis: {$experiment->thesis}\n\nCompleted Procedure:\n{$procedure}\n\nGenerate a reusable skill prompt that can replicate this procedure for similar goals.",
                teamId: $experiment->team_id,
                purpose: 'skill_generation',
                maxTokens: 2048,
            ));

            return $response->content;
        } catch (\Throwable $e) {
            Log::error('ProposeNewSkillFromExperimentAction: LLM synthesis failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
