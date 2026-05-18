<?php

namespace App\Console\Commands;

use App\Domain\Memory\Actions\DistillTeamEventsAction;
use App\Domain\Memory\Jobs\DistillTeamEventsJob;
use App\Domain\Shared\Models\Team;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class DistillEventsCommand extends Command
{
    protected $signature = 'memory:distill-events
                            {--team= : Distil events for a specific team only}
                            {--since= : Window start — relative (e.g. 24h, 7d) or an absolute date}
                            {--dry-run : Gather and report events without calling the LLM or storing memories}';

    protected $description = 'Distil each team\'s recent event stream into durable memory digests';

    public function handle(): int
    {
        if (! config('memory.distillation.enabled', true)) {
            $this->info('Event distillation is disabled.');

            return self::SUCCESS;
        }

        $since = $this->parseSince($this->option('since'));
        $dryRun = (bool) $this->option('dry-run');

        $teams = Team::query()
            ->when($this->option('team'), fn ($q, $teamId) => $q->whereKey($teamId))
            ->pluck('id');

        if ($teams->isEmpty()) {
            $this->info('No teams to process.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $action = app(DistillTeamEventsAction::class);
            $rows = $teams->map(fn (string $teamId) => $action->execute($teamId, $since, true));
            $this->table(['Team', 'Events', 'Window start'], $rows->map(fn ($r) => [
                $r['team_id'], $r['events'], $r['window_start'],
            ]));

            return self::SUCCESS;
        }

        $teams->each(fn (string $teamId) => DistillTeamEventsJob::dispatch($teamId, $since?->toIso8601String()));
        $this->info("Dispatched event distillation for {$teams->count()} team(s).");

        return self::SUCCESS;
    }

    private function parseSince(?string $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^(\d+)([hdw])$/', $value, $m)) {
            return match ($m[2]) {
                'h' => now()->subHours((int) $m[1]),
                'd' => now()->subDays((int) $m[1]),
                'w' => now()->subWeeks((int) $m[1]),
            };
        }

        return Carbon::parse($value);
    }
}
