<?php

namespace App\Domain\Skill\Jobs;

use App\Domain\Skill\Actions\RunSkillIterationAction;
use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SkillImprovementIterationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly string $benchmarkId,
        public readonly string $userId,
    ) {
        $this->onQueue('experiments');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("benchmark:{$this->benchmarkId}", releaseAfter: 30),
        ];
    }

    public function handle(RunSkillIterationAction $runIteration): void
    {
        $benchmark = SkillBenchmark::find($this->benchmarkId);

        if (! $benchmark) {
            Log::warning('SkillImprovementIterationJob: benchmark not found', ['id' => $this->benchmarkId]);

            return;
        }

        // Check cancellation flag
        if (Cache::get("skill_benchmark:{$this->benchmarkId}:cancel")) {
            $benchmark->update([
                'status' => BenchmarkStatus::Cancelled,
                'completed_at' => now(),
            ]);

            return;
        }

        // Check stopping conditions
        if (! $benchmark->shouldContinue()) {
            SkillBenchmarkCompleteJob::dispatch($this->benchmarkId);

            return;
        }

        $skill = Skill::find($benchmark->skill_id);

        if (! $skill) {
            Log::error('SkillImprovementIterationJob: skill not found', ['skill_id' => $benchmark->skill_id]);
            $benchmark->update(['status' => BenchmarkStatus::Failed, 'completed_at' => now()]);

            return;
        }

        try {
            $runIteration->execute($skill, $benchmark, $this->userId);
        } catch (Throwable $e) {
            Log::error('SkillImprovementIterationJob: unrecoverable error', [
                'benchmark_id' => $this->benchmarkId,
                'error' => $e->getMessage(),
            ]);

            // The RunSkillIterationAction already catches per-iteration errors and logs them.
            // Only unrecoverable errors (e.g. DB failure) reach here — treat as failed.
            $benchmark->update(['status' => BenchmarkStatus::Failed, 'completed_at' => now()]);

            return;
        }

        // Reload benchmark to check updated state
        $benchmark->refresh();

        if ($benchmark->shouldContinue()) {
            // Self-dispatch next iteration immediately
            static::dispatch($this->benchmarkId, $this->userId);
        } else {
            SkillBenchmarkCompleteJob::dispatch($this->benchmarkId);
        }
    }

    public function failed(Throwable $exception): void
    {
        $benchmark = SkillBenchmark::find($this->benchmarkId);

        if ($benchmark && ! $benchmark->status->isTerminal()) {
            $benchmark->update(['status' => BenchmarkStatus::Failed, 'completed_at' => now()]);
        }

        Log::error('SkillImprovementIterationJob failed', [
            'benchmark_id' => $this->benchmarkId,
            'error' => $exception->getMessage(),
        ]);
    }
}
