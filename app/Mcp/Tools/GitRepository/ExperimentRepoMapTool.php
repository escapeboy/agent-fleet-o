<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\RepoMapGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\Process\Process;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentRepoMapTool extends Tool
{
    protected string $name = 'experiment_get_repo_map';

    protected string $description = 'Returns the current repository map (file tree + key signatures) for all git repositories linked to the agent running the given experiment. The map is cached per HEAD commit SHA and refreshed automatically on new commits.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('Experiment UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('experiment_id'));

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        $agentId = $experiment->agent_id ?? null;
        if (! $agentId) {
            return Response::error('Experiment has no linked agent.');
        }

        // Load agent to get git_repository_ids
        $agent = $experiment->agent ?? null;
        $gitRepoIds = $agent?->config['git_repository_ids'] ?? [];

        if (empty($gitRepoIds)) {
            return Response::text(json_encode([
                'experiment_id' => $experiment->id,
                'repo_maps' => [],
                'message' => 'No git repositories linked to this agent.',
            ]));
        }

        $generator = app(RepoMapGenerator::class);
        $repoMaps = [];

        foreach ($gitRepoIds as $gitRepoId) {
            $repo = GitRepository::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->find($gitRepoId);

            if (! $repo) {
                continue;
            }

            $repoPath = $repo->config['local_path'] ?? $repo->config['cloned_path'] ?? null;

            if (! $repoPath || ! is_dir($repoPath)) {
                $repoMaps[] = [
                    'repository_id' => $repo->id,
                    'name' => $repo->name,
                    'url' => $repo->url,
                    'error' => 'Local clone path not configured or not accessible.',
                ];

                continue;
            }

            $headSha = $this->getHeadSha($repoPath);
            $cacheKey = "repo_map:{$gitRepoId}:".(($headSha !== null) ? $headSha : 'unknown');

            $map = Cache::remember($cacheKey, 3600, function () use ($generator, $repoPath, $repo) {
                $generated = $generator->generate($repoPath);

                return "## Repository: {$repo->name} ({$repo->url})\n\n{$generated}";
            });

            $repoMaps[] = [
                'repository_id' => $repo->id,
                'name' => $repo->name,
                'url' => $repo->url,
                'head_sha' => $headSha,
                'map' => $map,
            ];
        }

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'repo_maps' => $repoMaps,
        ]));
    }

    private function getHeadSha(string $repoPath): ?string
    {
        try {
            $process = new Process(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], timeout: 10);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
        } catch (\Throwable) {
            // Silently ignore
        }

        return null;
    }
}
