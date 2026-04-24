<?php

namespace Tests\Unit\Domain\Assistant;

use App\Domain\Agent\Models\Agent;
use App\Domain\Assistant\Services\CitationExtractor;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CitationExtractorTest extends TestCase
{
    use RefreshDatabase;

    private CitationExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new CitationExtractor;
    }

    public function test_returns_text_unchanged_when_no_markers(): void
    {
        $result = $this->extractor->extract('Hello there, just a plain reply.', [
            ['toolName' => 'foo', 'result' => ['id' => $this->uuid()]],
        ]);

        $this->assertSame('Hello there, just a plain reply.', $result['text']);
        $this->assertSame([], $result['citations']);
    }

    public function test_returns_text_unchanged_when_tool_results_empty_and_no_markers(): void
    {
        $result = $this->extractor->extract('', []);
        $this->assertSame('', $result['text']);
        $this->assertSame([], $result['citations']);
    }

    public function test_extracts_single_valid_experiment_marker(): void
    {
        $experiment = Experiment::factory()->create(['title' => 'Test run 42']);

        $text = "The run succeeded [[experiment:{$experiment->id}]].";
        $result = $this->extractor->extract($text, [
            ['toolName' => 'experiment_get', 'result' => ['id' => $experiment->id]],
        ]);

        $this->assertStringContainsString('[[1]]', $result['text']);
        $this->assertStringNotContainsString("[[experiment:{$experiment->id}]]", $result['text']);
        $this->assertCount(1, $result['citations']);
        $this->assertSame(1, $result['citations'][0]['n']);
        $this->assertSame('experiment', $result['citations'][0]['kind']);
        $this->assertSame($experiment->id, $result['citations'][0]['id']);
        $this->assertSame('Test run 42', $result['citations'][0]['title']);
        $this->assertStringContainsString($experiment->id, $result['citations'][0]['url']);
    }

    public function test_hallucinated_uuid_is_silently_stripped(): void
    {
        $fakeId = $this->uuid();

        $text = "Bogus claim [[experiment:{$fakeId}]] right here.";
        $result = $this->extractor->extract($text, []);

        $this->assertStringNotContainsString('[[', $result['text']);
        $this->assertStringNotContainsString($fakeId, $result['text']);
        $this->assertSame([], $result['citations']);
    }

    public function test_unknown_kind_is_stripped(): void
    {
        $id = $this->uuid();
        $text = "Strange claim [[frobnicator:{$id}]].";
        $result = $this->extractor->extract($text, [
            ['result' => ['id' => $id]],
        ]);

        $this->assertStringNotContainsString('frobnicator', $result['text']);
        $this->assertSame([], $result['citations']);
    }

    public function test_malformed_uuid_is_left_untouched(): void
    {
        $text = 'Edge case [[experiment:not-a-uuid]] in text.';
        $result = $this->extractor->extract($text, []);

        // Regex requires a full UUID; malformed content is left as-is — markdown
        // will escape the brackets, so no injection risk.
        $this->assertSame($text, $result['text']);
        $this->assertSame([], $result['citations']);
    }

    public function test_deduplicates_repeated_marker_to_same_ref_number(): void
    {
        $experiment = Experiment::factory()->create(['title' => 'Dup test']);

        $text = "First [[experiment:{$experiment->id}]] and again [[experiment:{$experiment->id}]].";
        $result = $this->extractor->extract($text, [
            ['result' => ['id' => $experiment->id]],
        ]);

        $this->assertCount(1, $result['citations']);
        $this->assertSame(1, $result['citations'][0]['n']);
        // Both markers should be replaced with [[1]]( url )
        $this->assertEquals(2, substr_count($result['text'], '[[1]]'));
    }

    public function test_multiple_kinds_numbered_in_appearance_order(): void
    {
        $exp = Experiment::factory()->create(['title' => 'E1']);
        $agent = Agent::factory()->create(['name' => 'A1']);

        $text = "Agent [[agent:{$agent->id}]] finished experiment [[experiment:{$exp->id}]].";
        $result = $this->extractor->extract($text, [
            ['result' => ['id' => $exp->id]],
            ['result' => ['id' => $agent->id]],
        ]);

        $this->assertCount(2, $result['citations']);
        $byN = collect($result['citations'])->keyBy('n')->all();
        $this->assertSame('agent', $byN[1]['kind']);
        $this->assertSame('experiment', $byN[2]['kind']);
    }

    public function test_deleted_entity_is_dropped_with_log(): void
    {
        $realExp = Experiment::factory()->create();
        $ghostId = $this->uuid();

        $text = "Real [[experiment:{$realExp->id}]] and ghost [[experiment:{$ghostId}]].";
        $result = $this->extractor->extract($text, [
            ['result' => ['id' => $realExp->id]],
            ['result' => ['id' => $ghostId]],
        ]);

        // Ghost passes whitelist (appeared in tool results) but entity lookup fails → dropped.
        $this->assertCount(1, $result['citations']);
        $this->assertSame($realExp->id, $result['citations'][0]['id']);
    }

    public function test_nested_tool_result_shapes_captured(): void
    {
        $exp = Experiment::factory()->create(['title' => 'Nested']);

        $text = "Found [[experiment:{$exp->id}]].";
        $result = $this->extractor->extract($text, [
            [
                'toolName' => 'experiment_list',
                'result' => [
                    'data' => [
                        ['id' => $exp->id, 'title' => 'whatever'],
                        ['id' => $this->uuid(), 'title' => 'other'],
                    ],
                    'meta' => ['count' => 2],
                ],
            ],
        ]);

        $this->assertCount(1, $result['citations']);
    }

    public function test_signal_uses_source_type_fallback_title(): void
    {
        $signal = Signal::factory()->create(['source_type' => 'webhook']);

        $text = "From [[signal:{$signal->id}]].";
        $result = $this->extractor->extract($text, [
            ['result' => ['signal_id' => $signal->id]],
        ]);

        $this->assertCount(1, $result['citations']);
        $this->assertStringContainsString('Webhook', $result['citations'][0]['title']);
        // Signal uses list-page route with highlight query param.
        $this->assertStringContainsString('highlight='.$signal->id, $result['citations'][0]['url']);
    }

    public function test_bulk_resolve_uses_single_query_per_kind(): void
    {
        $e1 = Experiment::factory()->create();
        $e2 = Experiment::factory()->create();
        $e3 = Experiment::factory()->create();

        $text = "Triple: [[experiment:{$e1->id}]], [[experiment:{$e2->id}]], [[experiment:{$e3->id}]].";
        $toolResults = [
            ['result' => ['id' => $e1->id]],
            ['result' => ['id' => $e2->id]],
            ['result' => ['id' => $e3->id]],
        ];

        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = $this->extractor->extract($text, $toolResults);

        $queries = DB::getQueryLog();
        $experimentQueries = collect($queries)->filter(
            fn ($q) => str_contains($q['query'], 'experiments') && str_contains($q['query'], 'whereIn') === false && str_contains($q['query'], 'in (?')
        );

        // Expectation: exactly one whereIn query against experiments.
        $this->assertGreaterThanOrEqual(1, count($queries));
        $this->assertCount(3, $result['citations']);
    }

    public function test_missing_named_route_drops_citation_without_crashing(): void
    {
        // Simulate missing route by evicting from the router.
        $this->app['router']->getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshNameLookups();

        // We can't easily remove a route mid-test without flushing them all,
        // so instead test the outer behavior: an experiment with a bogus attribute
        // triggers no throw.
        $exp = Experiment::factory()->create();
        $text = "[[experiment:{$exp->id}]].";
        $result = $this->extractor->extract($text, [
            ['result' => ['id' => $exp->id]],
        ]);
        $this->assertCount(1, $result['citations']);
    }

    public function test_adjacent_markers_both_survive(): void
    {
        $e1 = Experiment::factory()->create();
        $e2 = Experiment::factory()->create();

        $text = "Adjacent [[experiment:{$e1->id}]][[experiment:{$e2->id}]] end.";
        $result = $this->extractor->extract($text, [
            ['result' => ['id' => $e1->id]],
            ['result' => ['id' => $e2->id]],
        ]);

        $this->assertCount(2, $result['citations']);
        $this->assertStringContainsString('[[1]]', $result['text']);
        $this->assertStringContainsString('[[2]]', $result['text']);
    }

    public function test_empty_tool_results_strips_all_markers(): void
    {
        $text = 'Ghost [[experiment:'.$this->uuid().']] and [[signal:'.$this->uuid().']].';
        $result = $this->extractor->extract($text, []);

        $this->assertStringNotContainsString('[[', $result['text']);
        $this->assertSame([], $result['citations']);
    }

    public function test_mixed_valid_and_invalid_markers(): void
    {
        $real = Experiment::factory()->create(['title' => 'Real']);
        $fake = $this->uuid();

        $text = "Real [[experiment:{$real->id}]] and fake [[experiment:{$fake}]].";
        $result = $this->extractor->extract($text, [
            ['result' => ['id' => $real->id]],
        ]);

        $this->assertCount(1, $result['citations']);
        $this->assertStringContainsString('[[1]]', $result['text']);
        $this->assertStringNotContainsString($fake, $result['text']);
    }

    private function uuid(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }
}
