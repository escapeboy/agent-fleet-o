<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Enums\AnnotationRating;
use App\Domain\Skill\Exceptions\InsufficientAnnotationsException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillAnnotation;
use App\Domain\Skill\Models\SkillVersion;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use RuntimeException;

/**
 * Reads annotations for a skill version, builds a meta-prompt from
 * positive/negative few-shot examples, and produces a new SkillVersion
 * with an AI-improved prompt_template.
 *
 * Requires at least 1 good and 1 bad annotation; throws
 * InsufficientAnnotationsException otherwise.
 */
class GenerateImprovedSkillVersionAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly UpdateSkillAction $updateSkill,
    ) {}

    /**
     * Generate an improved prompt template for the given skill version.
     *
     * @param  Skill  $skill  The skill to improve
     * @param  SkillVersion  $version  The version whose annotations to use as training data
     * @param  string  $teamId  Team context for the AI call
     * @param  string  $userId  User who triggered the improvement
     * @return SkillVersion The newly created version
     *
     * @throws InsufficientAnnotationsException
     */
    public function execute(
        Skill $skill,
        SkillVersion $version,
        string $teamId,
        string $userId,
    ): SkillVersion {
        // 1. Load annotations for this version, partitioned by rating (up to 5 each)
        $annotations = SkillAnnotation::where('skill_version_id', $version->id)
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->get();

        $good = $annotations->where('rating', AnnotationRating::Good)->take(5)->values();
        $bad = $annotations->where('rating', AnnotationRating::Bad)->take(5)->values();

        // 2. Guard: require at least one of each polarity
        if ($good->isEmpty() || $bad->isEmpty()) {
            throw new InsufficientAnnotationsException(
                sprintf(
                    'Insufficient annotations: need ≥1 good and ≥1 bad, got %d good and %d bad.',
                    $good->count(),
                    $bad->count(),
                ),
            );
        }

        // 3. Build the meta-prompt
        $originalTemplate = $version->configuration['prompt_template']
            ?? $skill->configuration['prompt_template']
            ?? '';

        $metaPrompt = $this->buildMetaPrompt($originalTemplate, $good->all(), $bad->all());

        // 4. Call the AI gateway to generate the improved prompt
        $response = $this->gateway->complete(new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are a prompt engineering expert. Respond with ONLY the improved prompt template text — no explanation, no markdown fences.',
            userPrompt: $metaPrompt,
            maxTokens: 2048,
            userId: $userId,
            teamId: $teamId,
            purpose: 'skill_improvement',
        ));

        $improvedTemplate = trim($response->content);

        // 5. Validate the response is non-trivial
        if (empty($improvedTemplate)) {
            throw new RuntimeException('AI returned an empty prompt template.');
        }
        if (strlen($improvedTemplate) > 10_000) {
            throw new RuntimeException('AI returned a prompt template exceeding 10,000 characters.');
        }

        // 6. Merge improved template into configuration and create a new SkillVersion
        $newConfiguration = array_merge($skill->configuration ?? [], [
            'prompt_template' => $improvedTemplate,
        ]);

        $updatedSkill = $this->updateSkill->execute(
            $skill,
            ['configuration' => $newConfiguration],
            sprintf('AI-generated improvement based on %d annotations', $good->count() + $bad->count()),
            $userId,
        );

        // Return the freshly created version
        return SkillVersion::where('skill_id', $updatedSkill->id)
            ->orderByDesc('created_at')
            ->firstOrFail();
    }

    /**
     * Assemble the meta-prompt that instructs the AI to improve the template.
     *
     * @param  array<SkillAnnotation>  $good
     * @param  array<SkillAnnotation>  $bad
     */
    private function buildMetaPrompt(string $originalTemplate, array $good, array $bad): string
    {
        $lines = [];
        $lines[] = '## Current Prompt Template';
        $lines[] = $originalTemplate ?: '(empty)';
        $lines[] = '';

        $lines[] = '## Positive Examples (outputs that were rated GOOD)';
        foreach ($good as $annotation) {
            $lines[] = "Input: {$annotation->input}";
            $lines[] = "Output: {$annotation->output}";
            if ($annotation->note) {
                $lines[] = "Note: {$annotation->note}";
            }
            $lines[] = '---';
        }
        $lines[] = '';

        $lines[] = '## Negative Examples (outputs that were rated BAD)';
        foreach ($bad as $annotation) {
            $lines[] = "Input: {$annotation->input}";
            $lines[] = "Output: {$annotation->output}";
            if ($annotation->note) {
                $lines[] = "Note (what was wrong): {$annotation->note}";
            }
            $lines[] = '---';
        }
        $lines[] = '';

        $lines[] = '## Task';
        $lines[] = 'Rewrite the prompt template so that it would produce outputs similar to the positive examples and avoid the issues illustrated by the negative examples.';
        $lines[] = 'Preserve any {{variable}} placeholders present in the original template.';
        $lines[] = 'Respond with ONLY the improved prompt template — no preamble, no explanation.';

        return implode("\n", $lines);
    }
}
