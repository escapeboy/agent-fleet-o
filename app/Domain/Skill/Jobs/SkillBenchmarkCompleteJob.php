<?php

namespace App\Domain\Skill\Jobs;

use App\Domain\Shared\Services\NotificationService;
use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Notifications\SkillBenchmarkDigestNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SkillBenchmarkCompleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly string $benchmarkId,
    ) {
        $this->onQueue('default');
    }

    public function handle(NotificationService $notificationService): void
    {
        $benchmark = SkillBenchmark::with(['skill', 'iterationLogs'])->find($this->benchmarkId);

        if (! $benchmark) {
            Log::warning('SkillBenchmarkCompleteJob: benchmark not found', ['id' => $this->benchmarkId]);

            return;
        }

        if (! $benchmark->status->isTerminal()) {
            $benchmark->update([
                'status' => BenchmarkStatus::Completed,
                'completed_at' => now(),
            ]);
            $benchmark->refresh();
        }

        // Notify team owner(s) with a digest
        $owner = User::whereHas('teams', function ($q) use ($benchmark) {
            $q->where('teams.id', $benchmark->team_id)->where('team_user.role', 'owner');
        })->first();

        if ($owner) {
            $owner->notify(new SkillBenchmarkDigestNotification($benchmark));
        }
    }
}
