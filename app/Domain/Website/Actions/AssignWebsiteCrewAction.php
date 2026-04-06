<?php

namespace App\Domain\Website\Actions;

use App\Domain\Crew\Models\Crew;
use App\Domain\Website\Models\Website;
use InvalidArgumentException;

class AssignWebsiteCrewAction
{
    public function execute(Website $website, ?string $crewId): void
    {
        if ($crewId !== null) {
            $crew = Crew::find($crewId);

            if (! $crew || $crew->team_id !== $website->team_id) {
                throw new InvalidArgumentException('Crew not found or does not belong to the same team.');
            }
        }

        $website->update(['managing_crew_id' => $crewId]);
    }
}
