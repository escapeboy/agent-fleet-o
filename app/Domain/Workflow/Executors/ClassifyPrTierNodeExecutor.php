<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Services\PrTierClassifier;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketBasicAuthDriver;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Workflow node that classifies a Bitbucket PR into a risk tier (T1-T4).
 *
 * Inputs (resolved via {{...}} interpolation against upstream node outputs):
 *   pr_url           — Bitbucket PR URL (required)
 *   credential_id    — Bitbucket basic_auth credential UUID (required)
 *   promote_branch   — production branch that triggers the T4 floor (default: 'main')
 *
 * Output:
 *   tier             — 'T1' | 'T2' | 'T3' | 'T4'
 *   reason           — one-liner the approval inbox renders
 *   pr_url           — passed through for downstream nodes
 *   files_changed    — list of file paths
 *   files_count      — int
 *   lines_changed    — int (added + removed)
 *   target_branch    — the PR's destination branch (lowercase)
 *
 * On Bitbucket API failure the executor returns `{error: ...}` instead of
 * throwing — the next conditional node is expected to handle the error path.
 */
class ClassifyPrTierNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    private const PR_URL_PATTERN = '#bitbucket\.org/([^/]+/[^/]+)/pull-requests/(\d+)#';

    public function __construct(
        private readonly BitbucketBasicAuthDriver $bitbucket,
        private readonly PrTierClassifier $classifier,
    ) {}

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $context = $this->buildStepContext($step, $experiment);

        $prUrl = $this->interpolate((string) ($config['pr_url'] ?? '{{input.pr_url}}'), $context);
        $credentialId = $this->interpolate((string) ($config['credential_id'] ?? ''), $context);
        $promoteBranch = $this->interpolate((string) ($config['promote_branch'] ?? 'main'), $context);

        if ($prUrl === '' || $prUrl === '{{input.pr_url}}') {
            return ['error' => 'pr_url not resolvable from input'];
        }

        if ($credentialId === '') {
            return ['error' => 'credential_id is required in node config'];
        }

        if (! preg_match(self::PR_URL_PATTERN, $prUrl, $matches)) {
            return ['error' => "pr_url is not a Bitbucket PR URL: {$prUrl}"];
        }

        $repoSlug = $matches[1];
        $prId = (int) $matches[2];

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->find($credentialId);

        if (! $credential) {
            return ['error' => "credential {$credentialId} not found for team {$experiment->team_id}"];
        }

        try {
            $diffstat = $this->bitbucket->getPullRequestDiffStat($credential, $repoSlug, $prId);
        } catch (\Throwable $e) {
            Log::warning('ClassifyPrTierNodeExecutor: Bitbucket diffstat failed', [
                'pr_url' => $prUrl,
                'error' => $e->getMessage(),
            ]);

            return ['error' => "Bitbucket API error: {$e->getMessage()}"];
        }

        $files = array_map(fn (array $f): string => $f['path'], $diffstat['files']);
        $linesAdded = $diffstat['totals']['lines_added'];
        $linesRemoved = $diffstat['totals']['lines_removed'];

        $composerChanged = false;
        foreach ($diffstat['files'] as $file) {
            if (str_ends_with($file['path'], 'composer.json')) {
                $composerChanged = true;
                break;
            }
        }

        $tierResult = ($this->classifier)([
            'files_changed' => $files,
            'lines_added' => $linesAdded,
            'lines_removed' => $linesRemoved,
            'target_branch' => Str::lower($diffstat['destination_branch']),
            'promote_branch' => Str::lower($promoteBranch),
            'composer_json_changed' => $composerChanged,
        ]);

        return [
            'tier' => $tierResult['tier'],
            'reason' => $tierResult['reason'],
            'pr_url' => $prUrl,
            'files_changed' => $files,
            'files_count' => count($files),
            'lines_changed' => $linesAdded + $linesRemoved,
            'target_branch' => Str::lower($diffstat['destination_branch']),
        ];
    }
}
