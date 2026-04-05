<?php

namespace App\Domain\Website\Listeners;

use App\Domain\Crew\Events\CrewExecuted;
use App\Domain\Website\Actions\MaterializeWebsiteFromCrewAction;
use Illuminate\Contracts\Queue\ShouldQueue;

class MaterializeWebsiteOnCrewCompletedListener implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly MaterializeWebsiteFromCrewAction $materialize,
    ) {}

    public function handle(CrewExecuted $event): void
    {
        $this->materialize->execute($event->execution);
    }
}
