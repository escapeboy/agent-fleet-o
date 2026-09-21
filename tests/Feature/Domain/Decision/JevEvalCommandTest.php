<?php

namespace Tests\Feature\Domain\Decision;

use App\Domain\Decision\Models\DecisionEval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JevEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $datasetPath;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();

        config([
            'decision.drivers.jev.key' => 'sk-test-key',
            'decision.drivers.jev.base_url' => 'https://api.typesafe.ai',
            'decision.drivers.jev.model' => 'jev-1.13.0',
        ]);

        $this->datasetPath = tempnam(sys_get_temp_dir(), 'routing').'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->datasetPath);
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     */
    private function writeDataset(array $cases): void
    {
        file_put_contents(
            $this->datasetPath,
            implode("\n", array_map(static fn (array $c): string => json_encode($c), $cases))."\n",
        );
    }

    private function case(string $id, string $split, string $state = 'read the failing test'): array
    {
        return [
            'id' => $id,
            'state' => $state,
            'questions' => [
                'domain' => [
                    'type' => 'choice',
                    'instructions' => 'Which tool domain comes next?',
                    'criteria' => ['filesystem' => 'Files', 'shell' => 'Commands', 'other' => 'Anything else'],
                ],
            ],
            'gold' => ['domain' => 'filesystem'],
            'meta' => ['lang' => 'en', 'source' => 'phoenix', 'split' => $split],
        ];
    }

    private function fakeAnswer(string $choice = 'filesystem'): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => [
                'domain' => [
                    'type' => 'choice',
                    'choice' => $choice,
                    'probabilities' => ['filesystem' => 0.8, 'shell' => 0.15, 'other' => 0.05],
                    'confidence' => 0.77,
                ],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 8],
        ])]);
    }

    #[Test]
    public function it_scores_only_the_requested_split_and_records_one_row_per_question(): void
    {
        $this->writeDataset([$this->case('a', 'test'), $this->case('b', 'dev'), $this->case('c', 'test')]);
        $this->fakeAnswer();

        $this->artisan('jev:eval', ['dataset' => $this->datasetPath, '--split' => 'test'])
            ->assertSuccessful();

        $rows = DecisionEval::all();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['a', 'c'], $rows->pluck('case_id')->all());
        $this->assertSame('jev', $rows->first()->driver);
        $this->assertSame('jev-1.13.0', $rows->first()->model);
        $this->assertSame('domain', $rows->first()->question_id);
        $this->assertSame('en', $rows->first()->lang);
        $this->assertTrue($rows->first()->correct);
        $this->assertSame(120, $rows->first()->input_tokens);
        $this->assertEqualsWithDelta(0.77, $rows->first()->confidence, 1e-6);
        $this->assertSame(0.8, $rows->first()->probabilities['filesystem']);
        $this->assertCount(1, $rows->pluck('run_id')->unique());
    }

    #[Test]
    public function a_wrong_choice_is_recorded_as_incorrect(): void
    {
        $this->writeDataset([$this->case('a', 'test')]);
        $this->fakeAnswer('shell');

        $this->artisan('jev:eval', ['dataset' => $this->datasetPath, '--split' => 'test'])->assertSuccessful();

        $this->assertFalse(DecisionEval::first()->correct);
    }

    #[Test]
    public function repeat_writes_one_row_per_pass_so_determinism_can_be_measured(): void
    {
        $this->writeDataset([$this->case('a', 'test')]);
        $this->fakeAnswer();

        $this->artisan('jev:eval', ['dataset' => $this->datasetPath, '--split' => 'test', '--repeat' => 3])
            ->assertSuccessful();

        $this->assertSame([0, 1, 2], DecisionEval::orderBy('repeat_index')->pluck('repeat_index')->all());
    }

    #[Test]
    public function an_oversized_case_is_rejected_by_id_and_never_truncated(): void
    {
        // 4 chars per estimated token, so 160k characters is ~40k tokens.
        $this->writeDataset([
            $this->case('too-big', 'test', str_repeat('x', 160_000)),
            $this->case('fits', 'test'),
        ]);
        $this->fakeAnswer();

        $this->artisan('jev:eval', ['dataset' => $this->datasetPath, '--split' => 'test'])
            ->expectsOutputToContain('Rejected case [too-big]')
            ->assertSuccessful();

        $this->assertSame(['fits'], DecisionEval::pluck('case_id')->all());
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_failing_case_is_reported_and_the_command_exits_non_zero(): void
    {
        $this->writeDataset([$this->case('a', 'test')]);
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'bad question'], 422)]);

        $this->artisan('jev:eval', ['dataset' => $this->datasetPath, '--split' => 'test'])
            ->assertFailed();

        $this->assertSame(0, DecisionEval::count());
    }
}
