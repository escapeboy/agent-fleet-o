<?php

namespace App\Domain\Skill\Services;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoSkillCreationService
{
    public function __construct(
        private readonly EmbeddingService $embedder,
        private readonly AiGatewayInterface $gateway,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Run the auto-skill creation pipeline across all teams.
     * Loads completed experiments from the last 90 days, clusters by semantic
     * similarity, and generates draft skills for qualifying clusters.
     *
     * @return int Number of skills created
     */
    public function run(bool $dryRun = false): int
    {
        $created = 0;
        $cutoff = now()->subDays(90);

        // Load all completed experiments from last 90 days, grouped by team
        $experimentsByTeam = Experiment::withoutGlobalScopes()
            ->where('status', ExperimentStatus::Completed->value)
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')
            ->limit(200 * 100) // reasonable upper bound across all teams
            ->get(['id', 'team_id', 'title', 'thesis', 'meta'])
            ->groupBy('team_id');

        foreach ($experimentsByTeam as $teamId => $experiments) {
            // Take at most 200 most recent per team
            $experiments = $experiments->take(200);

            // Build items with embeddings
            $items = [];
            foreach ($experiments as $experiment) {
                $goalText = $experiment->thesis ?? $experiment->title;
                if (empty($goalText)) {
                    continue;
                }

                // Use cached embedding from meta if available
                $meta = $experiment->meta ?? [];
                $embedding = $meta['goal_embedding'] ?? null;

                if (! $embedding) {
                    try {
                        $embedding = $this->embedder->embed($goalText);
                        // Cache embedding in experiment meta to avoid re-embedding
                        $meta['goal_embedding'] = $embedding;
                        $experiment->withoutEvents(function () use ($experiment, $meta) {
                            $experiment->timestamps = false;
                            $experiment->update(['meta' => $meta]);
                            $experiment->timestamps = true;
                        });
                    } catch (\Throwable $e) {
                        Log::debug('AutoSkillCreationService: embedding failed, skipping', [
                            'experiment_id' => $experiment->id,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }
                }

                $items[$experiment->id] = [
                    'id' => $experiment->id,
                    'goal' => $goalText,
                    'title' => $experiment->title,
                    'embedding' => $embedding,
                ];
            }

            if (count($items) < 5) {
                continue;
            }

            $clusters = $this->cluster($items);

            foreach ($clusters as $cluster) {
                $skill = $this->generateDraftSkill((string) $teamId, $cluster, $dryRun);
                if ($skill !== null) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Greedy clustering: for each unassigned experiment, start a cluster.
     * Assign all others with cosine similarity >= threshold.
     * Return only clusters with >= minSize members.
     *
     * @param  array<string, array{id: string, goal: string, title: string, embedding: float[]}>  $items
     * @return array<array<string, mixed>>
     */
    private function cluster(array $items, float $threshold = 0.85, int $minSize = 5): array
    {
        $ids = array_keys($items);
        $assigned = [];
        $clusters = [];

        foreach ($ids as $seedId) {
            if (isset($assigned[$seedId])) {
                continue;
            }

            $cluster = [$items[$seedId]];
            $assigned[$seedId] = true;

            foreach ($ids as $candidateId) {
                if (isset($assigned[$candidateId])) {
                    continue;
                }

                $sim = $this->cosineSimilarity(
                    $items[$seedId]['embedding'],
                    $items[$candidateId]['embedding'],
                );

                if ($sim >= $threshold) {
                    $cluster[] = $items[$candidateId];
                    $assigned[$candidateId] = true;
                }
            }

            if (count($cluster) >= $minSize) {
                $clusters[] = $cluster;
            }
        }

        return $clusters;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0.0);
            $magA += $v * $v;
            $magB += ($b[$i] ?? 0.0) * ($b[$i] ?? 0.0);
        }
        $denom = sqrt($magA) * sqrt($magB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * Call LLM to generate a draft skill definition from a cluster of experiments.
     * If dry_run, only logs and returns null.
     */
    private function generateDraftSkill(string $teamId, array $cluster, bool $dryRun): ?Skill
    {
        $titles = array_map(fn ($item) => '- '.$item['title'], $cluster);
        $titlesText = implode("\n", array_slice($titles, 0, 20));

        $goals = array_unique(array_map(fn ($item) => $item['goal'], $cluster));
        $goalsText = implode("\n", array_slice(array_map(fn ($g) => '- '.Str::limit($g, 150), $goals), 0, 10));

        $userPrompt = "The following experiments share a common pattern:\n\nTitles:\n{$titlesText}\n\nGoal samples:\n{$goalsText}\n\n"
            ."Respond with ONLY a JSON object (no markdown) with these exact keys:\n"
            .'{"name": "string (short, descriptive skill name)", '
            .'"slug": "string (kebab-case slug)", '
            .'"description": "string (1-2 sentences)", '
            .'"input_schema": {"type": "object", "properties": {}}, '
            .'"expected_output": "string (what the skill produces)"}';

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                systemPrompt: 'You are a skill template generator. Analyse recurring experiment patterns and define reusable AI skills. Output ONLY valid JSON, no markdown fences.',
                userPrompt: $userPrompt,
                maxTokens: 512,
                teamId: $teamId,
                purpose: 'auto_skill_generation',
                temperature: 0.3,
            ));
        } catch (\Throwable $e) {
            Log::error('AutoSkillCreationService: LLM call failed', [
                'team_id' => $teamId,
                'cluster_size' => count($cluster),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $raw = trim($response->content ?? '');

        // Strip markdown fences if present
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', trim($raw));

        $parsed = json_decode($raw, true);
        if (! is_array($parsed) || empty($parsed['name'])) {
            Log::warning('AutoSkillCreationService: Failed to parse LLM JSON response', [
                'team_id' => $teamId,
                'raw' => Str::limit($raw, 500),
            ]);

            return null;
        }

        $name = Str::limit($parsed['name'], 120);
        $slug = Str::slug($parsed['slug'] ?? $name).'-auto';
        $description = $parsed['description'] ?? '';
        $inputSchema = is_array($parsed['input_schema'] ?? null) ? $parsed['input_schema'] : ['type' => 'object', 'properties' => []];
        $expectedOutput = $parsed['expected_output'] ?? '';

        if ($dryRun) {
            Log::info('AutoSkillCreationService: [dry-run] Would create skill', [
                'team_id' => $teamId,
                'name' => $name,
                'slug' => $slug,
                'cluster_size' => count($cluster),
            ]);

            return null;
        }

        $skill = Skill::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'type' => SkillType::Llm,
            'execution_type' => ExecutionType::Async,
            'status' => SkillStatus::Draft,
            'risk_level' => RiskLevel::Low,
            'input_schema' => $inputSchema,
            'output_schema' => ['type' => 'object', 'properties' => ['result' => ['type' => 'string', 'description' => $expectedOutput]]],
            'configuration' => [
                'auto_generated' => true,
                'cluster_size' => count($cluster),
                'source_experiment_ids' => array_column($cluster, 'id'),
            ],
            'meta' => [
                'auto_generated' => true,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);

        // Notify team owners
        try {
            $this->notificationService->notifyTeam(
                teamId: $teamId,
                type: 'auto_skill_draft',
                title: 'New Auto-Generated Skill Draft',
                body: "A draft skill \"{$name}\" was auto-generated from {$this->clusterCount(count($cluster))} similar experiments. Review and activate it.",
                data: ['skill_id' => $skill->id],
            );
        } catch (\Throwable $e) {
            Log::debug('AutoSkillCreationService: Notification failed', ['error' => $e->getMessage()]);
        }

        Log::info('AutoSkillCreationService: Created draft skill', [
            'team_id' => $teamId,
            'skill_id' => $skill->id,
            'skill_name' => $name,
            'cluster_size' => count($cluster),
        ]);

        return $skill;
    }

    private function clusterCount(int $count): string
    {
        return $count === 1 ? '1 similar experiment' : "{$count} similar experiments";
    }
}
