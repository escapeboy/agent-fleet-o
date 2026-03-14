<?php

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Enums\GitRepositoryStatus;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Throwable;

class TestGitConnectionAction
{
    public function __construct(private readonly GitOperationRouter $router) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function execute(GitRepository $repo): array
    {
        try {
            $client = $this->router->resolve($repo);
            $reachable = $client->ping();

            $status = $reachable ? GitRepositoryStatus::Active : GitRepositoryStatus::Error;
            $message = $reachable ? 'Connection successful.' : 'Repository is not reachable.';

            $repo->update([
                'last_ping_at' => now(),
                'last_ping_status' => $reachable ? 'ok' : 'error',
                'status' => $status,
            ]);

            return ['success' => $reachable, 'message' => $message];
        } catch (Throwable $e) {
            $repo->update([
                'last_ping_at' => now(),
                'last_ping_status' => 'error',
                'status' => GitRepositoryStatus::Error,
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
