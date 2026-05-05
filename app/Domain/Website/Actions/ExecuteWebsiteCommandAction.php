<?php

namespace App\Domain\Website\Actions;

use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Website\Models\Website;
use InvalidArgumentException;

class ExecuteWebsiteCommandAction
{
    public function __construct(private readonly ExecuteCrewAction $executeCrewAction) {}

    public function execute(Website $website, string $command, ?string $pageId = null): CrewExecution
    {
        if (! $website->managing_crew_id) {
            throw new InvalidArgumentException('No managing crew assigned to this website.');
        }

        $website->loadMissing('managingCrew');

        $publicUrl = $website->custom_domain
            ? 'https://'.$website->custom_domain
            : url('/websites/'.$website->slug);

        $goal = "Website: {$website->name} (slug: {$website->slug})\n"
            ."Website ID: {$website->id}\n"
            ."Public URL: {$publicUrl}\n";

        if ($pageId !== null) {
            $page = $website->pages->firstWhere('id', $pageId)
                ?? $website->pages()->find($pageId);

            if ($page) {
                $goal .= "\nTarget page: {$page->title} (/{$page->slug})\n"
                    ."Page ID: {$page->id}\n";
            }
        }

        $goal .= "\nCommand: {$command}";

        return $this->executeCrewAction->execute(
            crew: $website->managingCrew,
            goal: $goal,
            teamId: $website->team_id,
        );
    }
}
