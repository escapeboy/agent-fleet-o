<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Jobs;

use App\Domain\GitRepository\Actions\IndexRepositoryAction;
use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatches the IndexRepositoryAction for a single GitRepository.
 * Placed on the default queue since indexing is a background, non-critical operation.
 */
class IndexRepositoryJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(private readonly GitRepository $repository)
    {
        $this->onQueue('default');
    }

    public function handle(IndexRepositoryAction $action): void
    {
        $action->execute($this->repository);
    }
}
