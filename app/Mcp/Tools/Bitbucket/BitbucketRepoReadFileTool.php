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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[IsReadOnly]
#[IsIdempotent]
class BitbucketRepoReadFileTool extends Tool
{
    use HasStructuredErrors;
    use MapsBitbucketHttpErrors;

    protected string $name = 'bitbucket_repo_read_file';

    protected string $description = 'Read a single file from a Bitbucket Cloud repository within the credential\'s workspace. Returns raw file content. Use for fetching one or two reference files from repos the agent has not cloned.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('UUID of the basic_auth credential whose secret_data has {username, password, workspace}.')
                ->required(),
            'repo_slug' => $schema->string()
                ->description('Repository slug (e.g. "collector"). May be qualified as "workspace/slug"; workspace must match credential.')
                ->required(),
            'branch' => $schema->string()
                ->description('Branch name, tag, or commit SHA.')
                ->required(),
            'path' => $schema->string()
                ->description('File path relative to repo root.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
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
            $content = app(BitbucketBasicAuthDriver::class)->readFile(
                $credential,
                $request->get('repo_slug'),
                $request->get('branch'),
                $request->get('path'),
            );
        } catch (AccessDeniedHttpException $e) {
            return $this->permissionDeniedError($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        } catch (RequestException $e) {
            return $this->mapBitbucketHttpException($e);
        }

        return Response::text(json_encode([
            'repo_slug' => $request->get('repo_slug'),
            'branch' => $request->get('branch'),
            'path' => $request->get('path'),
            'content' => $content,
            'length' => strlen($content),
        ]));
    }
}
