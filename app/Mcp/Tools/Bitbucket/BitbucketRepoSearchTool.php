<?php

namespace App\Mcp\Tools\Bitbucket;

use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketBasicAuthDriver;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\Tools\Bitbucket\Concerns\MapsBitbucketHttpErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\RequestException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[IsReadOnly]
class BitbucketRepoSearchTool extends Tool
{
    use HasStructuredErrors;
    use MapsBitbucketHttpErrors;

    protected string $name = 'bitbucket_repo_search';

    protected string $description = 'Search for a regex or substring across files in a Bitbucket Cloud repository within the credential\'s workspace. Returns matching {path, line_number, line_content} entries. Use to locate symbols in repos the agent has not cloned.';

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
                ->description('Branch name. Bitbucket code search currently uses the repo\'s default branch; this argument is recorded but does not narrow results.')
                ->required(),
            'pattern' => $schema->string()
                ->description('Substring or regex to search for.')
                ->required(),
            'path_filter' => $schema->string()
                ->description('Optional glob to scope the search (e.g. "src/**/*.php"). Forwarded as `path:` qualifier.'),
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

        if ($credential->credential_type !== CredentialType::BasicAuth) {
            return $this->failedPreconditionError('Credential must be of type basic_auth.');
        }

        try {
            $hits = app(BitbucketBasicAuthDriver::class)->searchCode(
                $credential,
                $request->get('repo_slug'),
                $request->get('branch'),
                $request->get('pattern'),
                $request->get('path_filter'),
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
            'pattern' => $request->get('pattern'),
            'matches' => $hits,
            'count' => count($hits),
        ]));
    }
}
