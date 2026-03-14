<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\GitRepository\Actions\CreateGitRepositoryAction;
use App\Domain\GitRepository\Actions\TestGitConnectionAction;
use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Enums\GitRepositoryStatus;
use App\Domain\GitRepository\Models\GitRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Git Repositories
 */
class GitRepositoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $repositories = QueryBuilder::for(GitRepository::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('mode'),
                AllowedFilter::exact('provider'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name'])
            ->defaultSort('name')
            ->cursorPaginate(min((int) $request->input('per_page', 20), 100));

        return response()->json($repositories);
    }

    public function show(GitRepository $gitRepository): JsonResponse
    {
        return response()->json($gitRepository->load('credential'));
    }

    public function store(Request $request, CreateGitRepositoryAction $action): JsonResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'min:2', 'max:255'],
            'url'             => ['required', 'url', 'max:2048'],
            'mode'            => ['required', new Enum(GitRepoMode::class)],
            'provider'        => ['sometimes', new Enum(GitProvider::class)],
            'default_branch'  => ['sometimes', 'string', 'max:255'],
            'credential_id'   => ['nullable', 'uuid', 'exists:credentials,id'],
            'config'          => ['sometimes', 'array'],
        ]);

        $repo = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $validated['name'],
            url: $validated['url'],
            mode: GitRepoMode::from($validated['mode']),
            provider: isset($validated['provider']) ? GitProvider::from($validated['provider']) : GitProvider::detectFromUrl($validated['url']),
            defaultBranch: $validated['default_branch'] ?? 'main',
            credentialId: $validated['credential_id'] ?? null,
            config: $validated['config'] ?? [],
        );

        return response()->json($repo, 201);
    }

    public function update(Request $request, GitRepository $gitRepository): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['sometimes', 'string', 'min:2', 'max:255'],
            'default_branch' => ['sometimes', 'string', 'max:255'],
            'status'         => ['sometimes', new Enum(GitRepositoryStatus::class)],
            'credential_id'  => ['nullable', 'uuid', 'exists:credentials,id'],
            'config'         => ['sometimes', 'array'],
        ]);

        $gitRepository->fill($validated)->save();

        return response()->json($gitRepository->refresh());
    }

    public function destroy(GitRepository $gitRepository): JsonResponse
    {
        $gitRepository->delete();

        return response()->json(['message' => 'Git repository deleted.']);
    }

    public function test(GitRepository $gitRepository, TestGitConnectionAction $action): JsonResponse
    {
        $result = $action->execute($gitRepository);

        return response()->json($result);
    }
}
