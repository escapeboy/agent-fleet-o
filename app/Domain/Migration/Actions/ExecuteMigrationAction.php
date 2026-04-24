<?php

namespace App\Domain\Migration\Actions;

use App\Domain\Migration\Enums\MigrationStatus;
use App\Domain\Migration\Jobs\ExecuteMigrationJob;
use App\Domain\Migration\Models\MigrationRun;
use App\Domain\Migration\Services\Importers\ImporterRegistry;

final class ExecuteMigrationAction
{
    public function __construct(
        private readonly ImporterRegistry $registry,
    ) {}

    /**
     * @param  array<string, ?string>|null  $confirmedMapping
     */
    public function execute(MigrationRun $run, ?array $confirmedMapping = null): MigrationRun
    {
        if (! $run->status->canExecute()) {
            throw new \RuntimeException("Migration run {$run->id} cannot be executed from status {$run->status->value}");
        }

        $importer = $this->registry->resolve($run->entity_type);
        $supported = $importer->supportedAttributes();

        $mapping = $confirmedMapping ?? $run->proposed_mapping ?? [];
        $cleanMapping = [];
        foreach ($mapping as $header => $target) {
            if ($target === null || $target === '') {
                $cleanMapping[$header] = null;

                continue;
            }
            if (! is_string($target) || ! isset($supported[$target])) {
                throw new \RuntimeException("Invalid target attribute: {$target}");
            }
            $cleanMapping[$header] = $target;
        }

        $run->update([
            'confirmed_mapping' => $cleanMapping,
            'status' => MigrationStatus::Pending->value,
        ]);

        ExecuteMigrationJob::dispatch($run->id);

        return $run->refresh();
    }
}
