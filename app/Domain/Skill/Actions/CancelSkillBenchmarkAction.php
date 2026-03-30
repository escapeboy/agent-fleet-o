<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Models\SkillBenchmark;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class CancelSkillBenchmarkAction
{
    public function execute(SkillBenchmark $benchmark): SkillBenchmark
    {
        if ($benchmark->status->isTerminal()) {
            throw new RuntimeException("Benchmark {$benchmark->id} is already in terminal state: {$benchmark->status->value}.");
        }

        // Signal the running job to stop after the current iteration
        Cache::put("skill_benchmark:{$benchmark->id}:cancel", true, now()->addHours(2));

        $benchmark->update([
            'status' => BenchmarkStatus::Cancelled,
            'completed_at' => now(),
        ]);

        return $benchmark->fresh();
    }
}
