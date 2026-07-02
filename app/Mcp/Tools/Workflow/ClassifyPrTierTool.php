<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Services\PrTierClassifier;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketBasicAuthDriver;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\Tools\Bitbucket\Concerns\MapsBitbucketHttpErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[IsReadOnly]
#[IsIdempotent]
class ClassifyPrTierTool extends Tool
{
    use HasStructuredErrors;
    use MapsBitbucketHttpErrors;

    protected string $name = 'classify_pr_tier';

    protected string $description = 'Classify a Bitbucket pull request into a risk tier (T1 trivial, T2 medium, T3 high-risk, T4 promote_branch). Returns the tier and a one-line reason explaining the classification. Used by the bug-fix-merge workflow to decide between auto-merge and human approval. Pure read-only — does not modify the PR.';

    private const PR_URL_PATTERN = '#bitbucket\.org/([^/]+/[^/]+)/pull-requests/(\d+)#';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('UUID of the basic_auth credential whose secret_data has {username, password, workspace}.')
                ->required(),
            'pr_url' => $schema->string()
                ->description('Bitbucket pull request URL (https://bitbucket.org/{workspace}/{repo}/pull-requests/{id}).')
                ->required(),
            'promote_branch' => $schema->string()
                ->description('Production branch that triggers the T4 floor (e.g. "main", "master"). Default: "main".'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $prUrl = (string) $request->get('pr_url');
        if (! preg_match(self::PR_URL_PATTERN, $prUrl, $matches)) {
            return $this->invalidArgumentError('pr_url is not a Bitbucket pull request URL.');
        }

        $repoSlug = $matches[1];
        $prId = (int) $matches[2];
        $promoteBranch = (string) ($request->get('promote_branch') ?? 'main');

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('credential_id'));

        if (! $credential) {
            return $this->notFoundError('credential', (string) $request->get('credential_id'));
        }

        try {
            $diffstat = app(BitbucketBasicAuthDriver::class)->getPullRequestDiffStat($credential, $repoSlug, $prId);
        } catch (AccessDeniedHttpException $e) {
            return $this->permissionDeniedError($e->getMessage());
        } catch (RequestException $e) {
            return $this->mapBitbucketHttpException($e);
        } catch (\Throwable $e) {
            return $this->internalError('Bitbucket API error', $e->getMessage());
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

        $result = (app(PrTierClassifier::class))([
            'files_changed' => $files,
            'lines_added' => $linesAdded,
            'lines_removed' => $linesRemoved,
            'target_branch' => Str::lower($diffstat['destination_branch']),
            'promote_branch' => Str::lower($promoteBranch),
            'composer_json_changed' => $composerChanged,
        ]);

        return Response::text(json_encode([
            'tier' => $result['tier'],
            'reason' => $result['reason'],
            'pr_url' => $prUrl,
            'files_count' => count($files),
            'lines_changed' => $linesAdded + $linesRemoved,
            'target_branch' => Str::lower($diffstat['destination_branch']),
            'files_changed' => $files,
        ], JSON_PRETTY_PRINT));
    }
}
