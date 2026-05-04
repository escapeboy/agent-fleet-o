<?php

namespace App\Console\Commands;

use App\Domain\Memory\Services\MemoryDriftDetector;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\UserNotification;
use Illuminate\Console\Command;

class CheckMemoryDrift extends Command
{
    protected $signature = 'memory:check-drift
        {--notify : Dispatch UserNotification to team owners when threshold count is exceeded}
        {--min-drifted=5 : Minimum drifted facts in 24h to trigger a notification}';

    protected $description = 'Compute memory drift across all teams. Optionally notify owners when many facts drift in 24h.';

    public function handle(MemoryDriftDetector $detector): int
    {
        $minDrifted = max(1, (int) $this->option('min-drifted'));
        $notify = (bool) $this->option('notify');

        $threshold = $detector->threshold();
        $this->info("Drift threshold: cosine > {$threshold}.");

        $totalChecked = 0;
        $totalDrifted = 0;

        Team::query()->withoutGlobalScopes()->cursor()->each(function (Team $team) use ($detector, $minDrifted, $notify, &$totalChecked, &$totalDrifted) {
            $totalChecked++;
            $drifted = $detector->detectForTeam($team->id);
            if ($drifted === []) {
                return;
            }
            $totalDrifted += count($drifted);
            $this->line("  {$team->name}: ".count($drifted).' drifted facts');

            if ($notify && count($drifted) >= $minDrifted) {
                UserNotification::create([
                    'user_id' => $team->owner_id,
                    'team_id' => $team->id,
                    'type' => 'memory.drift_warning',
                    'data' => [
                        'count' => count($drifted),
                        'threshold' => $detector->threshold(),
                        'top_memory_ids' => array_slice(array_column($drifted, 'memory_id'), 0, 5),
                    ],
                ]);
            }
        });

        $this->info("Scanned {$totalChecked} teams; {$totalDrifted} drifted facts above threshold.");

        return self::SUCCESS;
    }
}
