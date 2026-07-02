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
class BitbucketPrCreateTool extends Tool
{
    use HasStructuredErrors;
    use MapsBitbucketHttpErrors;

    protected string $name = 'bitbucket_pr_create';

    protected string $description = 'Open a pull request in a Bitbucket Cloud repository within the credential\'s workspace. Returns {pr_number, pr_url, state}. The agent commits via the existing FleetQ git execution path; this tool only creates the PR.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('UUID of the basic_auth credential whose secret_data has {username, password, workspace}.')
                ->required(),
            'repo_slug' => $schema->string()
                ->description('Repository slug (e.g. "collector2"). May be qualified as "workspace/slug"; workspace must match credential.')
                ->required(),
            'source_branch' => $schema->string()
                ->description('Branch with the changes (head).')
                ->required(),
            'destination_branch' => $schema->string()
                ->description('Target branch (base).')
                ->required(),
            'title' => $schema->string()
                ->description('Pull request title.')
                ->required(),
            'description' => $schema->string()
                ->description('Pull request description (Markdown).')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('credential_id'));

        if (! $credential) {
            return $this->notFoundError('credential', $request->get('credential_id'));
        }

        try {
            $result = app(BitbucketBasicAuthDriver::class)->createPullRequest(
                $credential,
                $request->get('repo_slug'),
                $request->get('source_branch'),
                $request->get('destination_branch'),
                $request->get('title'),
                $request->get('description'),
            );
        } catch (AccessDeniedHttpException $e) {
            return $this->permissionDeniedError($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        } catch (RequestException $e) {
            return $this->mapBitbucketHttpException($e);
        }

        return Response::text(json_encode([
            'pr_number' => $result['pr_number'],
            'pr_url' => $result['pr_url'],
            'state' => $result['state'],
        ]));
    }
}
