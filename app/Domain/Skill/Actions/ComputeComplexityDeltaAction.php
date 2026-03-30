<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\SkillVersion;

/**
 * Computes the token-count difference between two skill version prompt templates.
 * A positive delta means the candidate is more complex than the baseline.
 * Uses whitespace tokenisation — sufficient accuracy for a penalty signal.
 */
class ComputeComplexityDeltaAction
{
    /**
     * @param  SkillVersion  $candidate  The newly proposed version
     * @param  SkillVersion  $baseline  The reference version (current best)
     * @return int Token delta (candidate tokens − baseline tokens)
     */
    public function execute(SkillVersion $candidate, SkillVersion $baseline): int
    {
        $candidateTokens = $this->tokenCount($candidate);
        $baselineTokens = $this->tokenCount($baseline);

        return $candidateTokens - $baselineTokens;
    }

    private function tokenCount(SkillVersion $version): int
    {
        /** @var array<string, mixed> $config */
        $config = $version->configuration ?? [];
        $template = (string) ($config['prompt_template'] ?? '');

        if (empty($template)) {
            return 0;
        }

        return count(preg_split('/\s+/', trim($template), -1, PREG_SPLIT_NO_EMPTY));
    }
}
