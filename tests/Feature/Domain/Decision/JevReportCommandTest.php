<?php

namespace Tests\Feature\Domain\Decision;

use App\Domain\Decision\Models\DecisionEval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JevReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runId = (string) Str::uuid7();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function report(array $arguments): string
    {
        $this->assertSame(0, Artisan::call('jev:report', $arguments));

        return Artisan::output();
    }

    private function row(array $overrides = []): void
    {
        DecisionEval::create(array_merge([
            'run_id' => $this->runId,
            'driver' => 'jev',
            'model' => 'jev-1.13.0',
            'dataset' => 'routing.jsonl',
            'case_id' => (string) Str::uuid7(),
            'question_id' => 'domain',
            'split' => 'test',
            'lang' => 'en',
            'answer' => ['type' => 'choice', 'choice' => 'filesystem'],
            'probabilities' => ['filesystem' => 0.9, 'shell' => 0.1],
            'confidence' => 0.85,
            'gold' => 'filesystem',
            'correct' => true,
            'latency_ms' => 120,
            'input_tokens' => 400,
            'repeat_index' => 0,
        ], $overrides));
    }

    #[Test]
    public function it_prints_the_headline_metrics_for_the_run(): void
    {
        $this->row();
        $this->row();
        $this->row([
            'answer' => ['type' => 'choice', 'choice' => 'shell'],
            'probabilities' => ['filesystem' => 0.4, 'shell' => 0.6],
            'confidence' => 0.55,
            'gold' => 'filesystem',
            'correct' => false,
            'latency_ms' => 800,
        ]);

        $output = $this->report(['run_id' => $this->runId]);

        $this->assertStringContainsString('routing.jsonl', $output);
        $this->assertStringContainsString('jev-1.13.0', $output);
        $this->assertStringContainsString('66.67%', $output);
        $this->assertStringContainsString('reliability', $output);
        $this->assertStringContainsString('coverage at target precision', $output);
    }

    #[Test]
    public function it_defaults_to_the_most_recent_run(): void
    {
        $this->row();

        $this->assertSame(0, Artisan::call('jev:report'));
    }

    #[Test]
    public function it_fails_loudly_on_an_unknown_run(): void
    {
        $this->row();

        $this->assertSame(1, Artisan::call('jev:report', ['run_id' => (string) Str::uuid7()]));
    }

    #[Test]
    public function cost_per_1k_decisions_splits_a_request_across_its_questions(): void
    {
        // One case, two questions, one request of 1000 input tokens: each decision
        // costs 500 tokens, not 1000.
        $caseId = (string) Str::uuid7();
        $this->row(['case_id' => $caseId, 'question_id' => 'domain', 'input_tokens' => 1000]);
        $this->row(['case_id' => $caseId, 'question_id' => 'tool', 'input_tokens' => 1000, 'gold' => 'Read', 'answer' => ['type' => 'choice', 'choice' => 'Read']]);

        config(['decision.pricing_usd_per_mtok.jev' => 0.042]);

        // 500 tokens/decision * 1000 decisions * $0.042/Mtok = $0.021
        $this->assertStringContainsString('$0.0210', $this->report(['run_id' => $this->runId]));
    }

    #[Test]
    public function determinism_is_reported_once_a_case_has_been_repeated(): void
    {
        $caseId = (string) Str::uuid7();
        $this->row(['case_id' => $caseId, 'repeat_index' => 0, 'probabilities' => ['filesystem' => 0.9, 'shell' => 0.1]]);
        $this->row(['case_id' => $caseId, 'repeat_index' => 1, 'probabilities' => ['filesystem' => 0.7, 'shell' => 0.3]]);

        $output = $this->report(['run_id' => $this->runId]);

        $this->assertStringNotContainsString('n/a (single pass)', $output);
        // Sample stddev of [0.9, 0.7] is 0.14142...
        $this->assertStringContainsString('0.14142', $output);
    }

    #[Test]
    public function variant_flip_rate_is_reported_when_the_dataset_carries_base_ids(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'variants').'.jsonl';

        $question = [
            'type' => 'choice',
            'instructions' => 'Which tool domain comes next?',
            'criteria' => ['filesystem' => 'Files', 'shell' => 'Commands', 'other' => 'Anything else'],
        ];

        file_put_contents($path, implode("\n", [
            json_encode(['id' => 'c1', 'state' => 's', 'questions' => ['domain' => $question], 'gold' => ['domain' => 'filesystem'], 'meta' => ['variant' => 'clean', 'base_id' => 'b1', 'split' => 'test']]),
            json_encode(['id' => 'c1-typo', 'state' => 's', 'questions' => ['domain' => $question], 'gold' => ['domain' => 'filesystem'], 'meta' => ['variant' => 'typo', 'base_id' => 'b1', 'split' => 'test']]),
        ])."\n");

        $this->row(['case_id' => 'c1', 'probabilities' => ['filesystem' => 0.9, 'shell' => 0.1]]);
        $this->row([
            'case_id' => 'c1-typo',
            'answer' => ['type' => 'choice', 'choice' => 'shell'],
            'probabilities' => ['filesystem' => 0.3, 'shell' => 0.7],
            'correct' => false,
        ]);

        try {
            $output = $this->report(['run_id' => $this->runId, '--dataset-path' => $path]);
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('Variant robustness', $output);
        $this->assertStringContainsString('typo', $output);
        // The typo variant flipped the answer on the only pair, and P(gold) fell 0.9 -> 0.3.
        $this->assertStringContainsString('100.00%', $output);
        $this->assertStringContainsString('-0.6000', $output);
    }
}
