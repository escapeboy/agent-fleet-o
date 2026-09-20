<?php

namespace App\Console\Commands;

use App\Domain\Decision\Contracts\BatchDecisionModel;
use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\Answer;
use App\Domain\Decision\DTOs\DatasetCase;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\Models\DecisionEval;
use App\Domain\Decision\Services\DatasetLoader;
use App\Domain\Decision\Services\DecisionDriverFactory;
use App\Domain\Decision\Services\DecisionRateLimiter;
use App\Domain\Decision\Services\TokenEstimator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class JevEvalCommand extends Command
{
    protected $signature = 'jev:eval
        {dataset : Path to the JSONL dataset}
        {--driver=jev : Driver key from config/decision.php}
        {--split=test : Only evaluate cases in this split (dev, test, or all)}
        {--repeat=1 : Send each case N times, to measure determinism}
        {--concurrency=8 : Cases in flight at once (batch-capable drivers only)}';

    protected $description = 'Run a decision-model eval over a JSONL dataset and record every answer.';

    public function handle(
        DatasetLoader $loader,
        DecisionDriverFactory $factory,
    ): int {
        $path = (string) $this->argument('dataset');
        $driverName = (string) $this->option('driver');
        $split = (string) $this->option('split');
        $repeat = max(1, (int) $this->option('repeat'));
        $concurrency = max(1, (int) $this->option('concurrency'));

        $driver = $factory->make($driverName);
        $runId = (string) Str::uuid7();
        $dataset = basename($path);
        $stateLimit = (int) config('decision.limits.state_tokens', 32_000);

        $limiter = new DecisionRateLimiter(
            requestsPerMinute: (int) config('decision.limits.requests_per_minute', 1_100),
            tokensPerSecond: (int) config('decision.limits.tokens_per_second', 225_000),
        );

        $this->info("Run {$runId} — driver [{$driverName}] model [{$driver->model()}] dataset [{$dataset}]");

        $cases = [];
        $rejected = 0;

        foreach ($loader->load($path, $split === 'all' ? null : $split) as $case) {
            $estimate = TokenEstimator::estimateStateWithLongestQuestion($case->state, $case->questions);

            if ($estimate > $stateLimit) {
                // Never truncate: a shortened state is a different case, and scoring
                // it would silently move the accuracy number.
                $this->warn("Rejected case [{$case->id}] — estimated {$estimate} tokens exceeds the {$stateLimit} token limit.");
                $rejected++;

                continue;
            }

            $cases[] = $case;
        }

        if ($cases === []) {
            $this->error('No cases to evaluate.');

            return self::FAILURE;
        }

        $total = count($cases) * $repeat;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $written = 0;
        $failed = 0;

        for ($repeatIndex = 0; $repeatIndex < $repeat; $repeatIndex++) {
            foreach (array_chunk($cases, $driver instanceof BatchDecisionModel ? $concurrency : 1) as $chunk) {
                $results = $this->evaluateChunk($driver, $limiter, $chunk);

                foreach ($chunk as $case) {
                    $result = $results[$case->id] ?? null;

                    if ($result instanceof DecisionResult) {
                        $written += $this->record($runId, $driverName, $dataset, $case, $result, $repeatIndex);
                    } else {
                        $failed++;
                        $message = $result instanceof Throwable ? $result->getMessage() : 'no result';
                        $this->newLine();
                        $this->warn("Case [{$case->id}] failed: {$message}");
                    }

                    $bar->advance();
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Wrote {$written} answer rows. Cases failed: {$failed}. Cases rejected on size: {$rejected}.");
        $this->line("Report with: php artisan jev:report {$runId}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<DatasetCase>  $chunk
     * @return array<string, DecisionResult|Throwable>
     */
    private function evaluateChunk(DecisionModel $driver, DecisionRateLimiter $limiter, array $chunk): array
    {
        foreach ($chunk as $case) {
            $limiter->acquire(TokenEstimator::estimateStateWithLongestQuestion($case->state, $case->questions));
        }

        if ($driver instanceof BatchDecisionModel && count($chunk) > 1) {
            $requests = [];

            foreach ($chunk as $case) {
                $requests[$case->id] = ['state' => $case->state, 'questions' => $case->questions];
            }

            return $driver->decideBatch($requests);
        }

        $results = [];

        foreach ($chunk as $case) {
            try {
                $results[$case->id] = $driver->decide($case->state, $case->questions);
            } catch (Throwable $e) {
                $results[$case->id] = $e;
            }
        }

        return $results;
    }

    private function record(
        string $runId,
        string $driverName,
        string $dataset,
        DatasetCase $case,
        DecisionResult $result,
        int $repeatIndex,
    ): int {
        $rows = [];

        foreach ($case->gold as $questionId => $gold) {
            $answer = $result->answer((string) $questionId);

            if ($answer === null) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'run_id' => $runId,
                'driver' => $driverName,
                'model' => $result->model,
                'dataset' => $dataset,
                'case_id' => $case->id,
                'question_id' => (string) $questionId,
                'split' => $case->split,
                'lang' => $case->lang(),
                'answer' => json_encode($answer->toArray()),
                'probabilities' => $answer->probabilities() === null ? null : json_encode($answer->probabilities()),
                'confidence' => $answer->confidence(),
                'gold' => json_encode($gold),
                'correct' => $this->isCorrect($answer, $gold),
                'latency_ms' => $result->latencyMs,
                'input_tokens' => $result->inputTokens,
                'repeat_index' => $repeatIndex,
                'created_at' => now(),
            ];
        }

        if ($rows !== []) {
            DecisionEval::insert($rows);
        }

        return count($rows);
    }

    /**
     * Choice is an exact match. Score and Noul are continuous, so gold is the
     * level (or the yes/no side) and the answer is graded by which level it
     * rounds to — comparing floats for equality would score everything wrong.
     */
    private function isCorrect(Answer $answer, mixed $gold): bool
    {
        return match ($answer->type()) {
            'choice' => (string) $answer->value() === (string) $gold,
            'score' => is_numeric($gold) && round((float) $answer->value()) === round((float) $gold),
            'noul' => is_numeric($gold)
                ? ((float) $answer->value() >= 0.5) === ((float) $gold >= 0.5)
                : ((float) $answer->value() >= 0.5) === filter_var($gold, FILTER_VALIDATE_BOOLEAN),
            default => false,
        };
    }
}
