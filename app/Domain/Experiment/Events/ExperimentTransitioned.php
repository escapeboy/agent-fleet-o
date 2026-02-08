<?php

namespace App\Domain\Experiment\Events;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExperimentTransitioned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Experiment $experiment,
        public readonly ExperimentStatus $fromState,
        public readonly ExperimentStatus $toState,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('experiments'),
            new Channel("experiment.{$this->experiment->id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'experiment_id' => $this->experiment->id,
            'from_state' => $this->fromState->value,
            'to_state' => $this->toState->value,
            'title' => $this->experiment->title,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
