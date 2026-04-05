<?php

namespace App\Console\Commands;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Console\Command;

class RecoverStuckGeneratingWebsites extends Command
{
    protected $signature = 'websites:recover-stuck';

    protected $description = 'Transition Generating websites back to Draft when their crew execution has failed or been terminated.';

    public function handle(): int
    {
        $terminalFailures = [
            CrewExecutionStatus::Failed->value,
            CrewExecutionStatus::Terminated->value,
        ];

        // Websites whose crew execution reached a terminal failure state
        $stuck = Website::withoutGlobalScopes()
            ->where('status', WebsiteStatus::Generating)
            ->whereHas('crewExecution', fn ($q) => $q->whereIn('status', $terminalFailures))
            ->get();

        foreach ($stuck as $website) {
            $website->update([
                'status' => WebsiteStatus::Draft,
                // Only replace the placeholder name — don't clobber a user-set name
                'name' => $website->name === 'Generating…' ? 'Failed generation' : $website->name,
            ]);
            $this->line("Recovered website {$website->id} (crew failed).");
        }

        // Orphaned: been Generating for >2 hours with no linked execution
        $orphaned = Website::withoutGlobalScopes()
            ->where('status', WebsiteStatus::Generating)
            ->whereNull('crew_execution_id')
            ->where('created_at', '<', now()->subHours(2))
            ->get();

        foreach ($orphaned as $website) {
            $website->update([
                'status' => WebsiteStatus::Draft,
                'name' => $website->name === 'Generating…' ? 'Failed generation' : $website->name,
            ]);
            $this->line("Recovered orphaned website {$website->id} (no execution linked).");
        }

        $total = $stuck->count() + $orphaned->count();
        $this->info("Recovered {$total} website(s) total.");

        return Command::SUCCESS;
    }
}
