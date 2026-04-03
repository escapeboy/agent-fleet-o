<?php

namespace App\Console\Commands;

use App\Domain\Shared\Jobs\ScoreContactHealthJob;
use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Console\Command;

class ScoreContactHealthCommand extends Command
{
    protected $signature = 'contacts:score-health {--team= : Limit scoring to a specific team ID} {--chunk=100 : Number of records to process per chunk}';

    protected $description = 'Score relationship health for contact identities';

    public function handle(): int
    {
        $teamId = $this->option('team');
        $chunk = (int) $this->option('chunk');

        $query = ContactIdentity::withoutGlobalScopes();

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        $dispatched = 0;

        $query->select('id')->chunk($chunk, function ($contacts) use (&$dispatched) {
            foreach ($contacts as $contact) {
                ScoreContactHealthJob::dispatch($contact->id);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} health scoring job(s).");

        return self::SUCCESS;
    }
}
