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

    #[Test]
    public function it_reports_top_2_accuracy_per_class_scores_and_confusion_pairs_for_choice(): void
    {
        // Gold filesystem, argmax shell, but filesystem is the runner-up: wrong
        // at top-1, right at top-2.
        $this->row([
            'answer' => ['type' => 'choice', 'choice' => 'shell'],
            'probabilities' => ['shell' => 0.6, 'filesystem' => 0.3, 'web' => 0.1],
            'gold' => 'filesystem',
            'correct' => false,
        ]);
        $this->row([
            'answer' => ['type' => 'choice', 'choice' => 'shell'],
            'probabilities' => ['shell' => 0.7, 'web' => 0.2, 'filesystem' => 0.1],
            'gold' => 'web',
            'correct' => false,
        ]);
        $this->row();

        $output = $this->report(['run_id' => $this->runId]);

        $this->assertStringContainsString('top-2 accuracy: 100.00%  (3/3', $output);
        $this->assertStringContainsString('per-class precision / recall / F1', $output);
        $this->assertStringContainsString('confusion pairs', $output);
        $this->assertStringContainsString('filesystem → shell', $output);
        $this->assertStringContainsString('web → shell', $output);
    }

    #[Test]
    public function it_reports_top_2_as_unavailable_without_probabilities(): void
    {
        $this->row(['probabilities' => null, 'confidence' => null]);

        $output = $this->report(['run_id' => $this->runId]);

        $this->assertStringContainsString('top-2 accuracy: n/a (no probabilities)', $output);
    }

    #[Test]
    public function it_reports_positive_class_metrics_and_positive_rate_for_noul(): void
    {
        $noul = static fn (float $p, bool $gold, bool $correct): array => [
            'question_id' => 'has_contractor',
            'answer' => ['type' => 'noul', 'noul' => $p],
            'probabilities' => null,
            'confidence' => null,
            'gold' => $gold,
            'correct' => $correct,
        ];

        $this->row($noul(0.9, true, true));
        $this->row($noul(0.8, true, true));
        $this->row($noul(0.7, false, false));
        $this->row($noul(0.1, false, true));

        $output = $this->report(['run_id' => $this->runId]);

        // 3/4 correct, gold positive in 2 of 4.
        $this->assertStringContainsString('positive rate 50.00%', $output);
        $this->assertStringContainsString('positive class (statement is true)', $output);
        $this->assertStringContainsString('precision @ t=0.5', $output);
        $this->assertStringContainsString('66.67% (2/3)', $output);
        $this->assertStringContainsString('100.00% (2/2)', $output);
        $this->assertStringContainsString('PR-AUC (average precision)', $output);
        $this->assertStringContainsString('1.0000', $output);
    }

    #[Test]
    public function it_reports_mae_and_binary_accuracy_for_score(): void
    {
        $score = static fn (float $value, int $gold, bool $correct): array => [
            'question_id' => 'financial_impact',
            'answer' => ['type' => 'score', 'score' => $value],
            'probabilities' => null,
            'confidence' => null,
            'gold' => $gold,
            'correct' => $correct,
        ];

        $this->row($score(0, 0, true));
        $this->row($score(1, 3, false));   // off by 2, both sides positive
        $this->row($score(2, 0, false));   // off by 2, crosses the 0 boundary

        $output = $this->report(['run_id' => $this->runId]);

        $this->assertStringContainsString('MAE (level index)', $output);
        $this->assertStringContainsString('1.3333', $output);
        $this->assertStringContainsString('binary accuracy (level 0 vs > 0)', $output);
        $this->assertStringContainsString('66.67%  (2/3', $output);
    }

    #[Test]
    public function it_scores_class_metrics_on_the_label_a_score_or_noul_answer_asserts(): void
    {
        // A Score answer is a position on the scale, not a level index, and a
        // Noul answer is a probability, not a verdict. Comparing either raw
        // against the gold label matches nothing and reports macro-F1 0.
        $this->row([
            'question_id' => 'financial_impact',
            'answer' => ['type' => 'score', 'score' => 0.18],
            'probabilities' => null,
            'confidence' => 0.82,
            'gold' => 0,
            'correct' => true,
        ]);
        $this->row([
            'question_id' => 'financial_impact',
            'answer' => ['type' => 'score', 'score' => 2.9],
            'probabilities' => null,
            'confidence' => 0.7,
            'gold' => 3,
            'correct' => true,
        ]);

        $output = $this->report(['run_id' => $this->runId, '--questions' => 'financial_impact']);

        $this->assertMatchesRegularExpression('/macro-F1\s+\|\s+1\.0000/', $output);
        $this->assertDoesNotMatchRegularExpression('/macro-F1\s+\|\s+0\.0000/', $output);
    }
}
