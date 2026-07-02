<?php

namespace App\Mcp\Tools\Bitbucket;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketBasicAuthDriver;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\Tools\Bitbucket\Concerns\MapsBitbucketHttpErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\RequestException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[IsDestructive]
class BitbucketPrManageTool extends Tool
{
    use HasStructuredErrors;
    use MapsBitbucketHttpErrors;

    protected string $name = 'bitbucket_pr_manage';

    protected string $description = 'Follow up on an existing Bitbucket pull request. Actions: comment (add a comment), close (decline the PR), merge (merge the PR with optional strategy).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('UUID of the basic_auth credential whose secret_data has {username, password, workspace}.')
                ->required(),
            'repo_slug' => $schema->string()
                ->description('Repository slug. May be qualified as "workspace/slug"; workspace must match credential.')
                ->required(),
            'pr_id' => $schema->integer()
                ->description('Pull request id (numeric).')
                ->required(),
            'action' => $schema->string()
                ->description('One of: comment, close, merge.')
                ->required(),
            'body' => $schema->string()
                ->description('Comment body (required when action=comment, ignored otherwise).'),
            'merge_strategy' => $schema->string()
                ->description('Optional merge strategy: merge_commit, squash, fast_forward (used when action=merge).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $action = $request->get('action');
        if (! in_array($action, ['comment', 'close', 'merge'], true)) {
            return $this->invalidArgumentError("Unknown action `{$action}`. Must be one of: comment, close, merge.");
        }

        if ($action === 'comment' && ((string) $request->get('body', '')) === '') {
            return $this->invalidArgumentError('`body` is required when action=comment.');
        }

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('credential_id'));

        if (! $credential) {
            return $this->notFoundError('credential', $request->get('credential_id'));
        }

        $driver = app(BitbucketBasicAuthDriver::class);
        $repoSlug = $request->get('repo_slug');
        $prId = (int) $request->get('pr_id');

        try {
            $result = match ($action) {
                'comment' => $driver->commentOnPullRequest($credential, $repoSlug, $prId, $request->get('body')),
                'close' => $driver->closePullRequest($credential, $repoSlug, $prId),
                'merge' => $driver->mergePullRequest($credential, $repoSlug, $prId, $request->get('merge_strategy')),
            };
        } catch (AccessDeniedHttpException $e) {
            return $this->permissionDeniedError($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        } catch (RequestException $e) {
            return $this->mapBitbucketHttpException($e);
        }

        return Response::text(json_encode([
            'action' => $action,
            'pr_id' => $prId,
            'repo_slug' => $repoSlug,
            'state' => $result['state'] ?? null,
            'result' => $result,
        ]));
    }
}
