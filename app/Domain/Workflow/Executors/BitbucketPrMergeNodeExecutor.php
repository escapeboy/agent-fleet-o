<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketBasicAuthDriver;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Workflow node that merges a Bitbucket pull request via the API.
 *
 * Inputs (resolved via {{...}} interpolation):
 *   pr_url           — Bitbucket PR URL (required)
 *   credential_id    — Bitbucket basic_auth credential UUID (required)
 *   merge_strategy   — optional: merge_commit | squash | fast_forward (default: merge_commit)
 *
 * Output:
 *   merged           — bool
 *   merge_sha        — string (when merged)
 *   error            — string (when not merged)
 *
 * Idempotency: Bitbucket's merge endpoint is idempotent for already-merged PRs
 * — repeated calls return the existing merge state without re-merging. We do
 * not write to a local table here; correlation back to the bug-report Signal
 * happens through the existing Bitbucket `pullrequest:fulfilled` webhook
 * picked up by CloseBugReportOnPrMergeListener.
 *
 * Branch-protection failures (HTTP 405) are caught and returned as
 * `{merged: false, error: <message>}` so the workflow ends gracefully and the
 * approval inbox can surface the reason — never throw.
 */
class BitbucketPrMergeNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    private const PR_URL_PATTERN = '#bitbucket\.org/([^/]+/[^/]+)/pull-requests/(\d+)#';

    public function __construct(
        private readonly BitbucketBasicAuthDriver $bitbucket,
    ) {}

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $context = $this->buildStepContext($step, $experiment);

        $prUrl = $this->interpolate((string) ($config['pr_url'] ?? '{{input.pr_url}}'), $context);
        $credentialId = $this->interpolate((string) ($config['credential_id'] ?? ''), $context);
        $mergeStrategy = $this->interpolate((string) ($config['merge_strategy'] ?? 'merge_commit'), $context);

        if ($prUrl === '' || $prUrl === '{{input.pr_url}}') {
            return ['merged' => false, 'error' => 'pr_url not resolvable from input'];
        }

        if ($credentialId === '') {
            return ['merged' => false, 'error' => 'credential_id is required in node config'];
        }

        if (! preg_match(self::PR_URL_PATTERN, $prUrl, $matches)) {
            return ['merged' => false, 'error' => "pr_url is not a Bitbucket PR URL: {$prUrl}"];
        }

        $repoSlug = $matches[1];
        $prId = (int) $matches[2];

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->find($credentialId);

        if (! $credential) {
            return ['merged' => false, 'error' => "credential {$credentialId} not found for team {$experiment->team_id}"];
        }

        try {
            $response = $this->bitbucket->mergePullRequest($credential, $repoSlug, $prId, $mergeStrategy);
        } catch (RequestException $e) {
            $body = $e->response?->json() ?? [];
            $message = $body['error']['message'] ?? $e->getMessage();

            Log::warning('BitbucketPrMergeNodeExecutor: merge request failed', [
                'pr_url' => $prUrl,
                'status' => $e->response?->status(),
                'error' => $message,
            ]);

            return [
                'merged' => false,
                'error' => $message,
            ];
        } catch (\Throwable $e) {
            Log::error('BitbucketPrMergeNodeExecutor: unexpected error', [
                'pr_url' => $prUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'merged' => false,
                'error' => $e->getMessage(),
            ];
        }

        $state = (string) ($response['state'] ?? '');
        $mergeSha = (string) ($response['merge_commit']['hash'] ?? '');
        $merged = $state === 'MERGED' || $mergeSha !== '';

        return [
            'merged' => $merged,
            'merge_sha' => $mergeSha,
        ];
    }
}
