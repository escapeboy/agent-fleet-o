<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Models\Signal;

class ManualSignalConnector
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
    ) {}

    /**
     * Ingest a manual signal and optionally create and start an experiment.
     */
    public function ingest(
        string $userId,
        string $title,
        string $thesis,
        string $track,
        array $payload = [],
        bool $autoStart = false,
        int $budgetCapCredits = 10000,
    ): array {
        $signal = $this->ingestAction->execute(
            sourceType: 'manual',
            sourceIdentifier: "user:{$userId}",
            payload: array_merge(['title' => $title, 'thesis' => $thesis], $payload),
            tags: ['manual', $track],
        );

        if (!$signal) {
            return ['signal' => null, 'experiment' => null];
        }

        $experiment = $this->createExperiment->execute(
            userId: $userId,
            title: $title,
            thesis: $thesis,
            track: $track,
            budgetCapCredits: $budgetCapCredits,
        );

        $signal->update(['experiment_id' => $experiment->id]);

        if ($autoStart) {
            $experiment = $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Auto-started from manual signal',
            );
        }

        return ['signal' => $signal, 'experiment' => $experiment];
    }
}
