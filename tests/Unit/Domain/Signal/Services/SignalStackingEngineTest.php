<?php

namespace Tests\Unit\Domain\Signal\Services;

use App\Domain\Signal\Models\CompanyIntentScore;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SignalStackingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SignalStackingEngineTest extends TestCase
{
    use RefreshDatabase;

    private SignalStackingEngine $engine;

    private string $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SignalStackingEngine;
        $this->teamId = Str::uuid()->toString();
    }

    /** @test */
    public function it_returns_zero_score_for_entity_with_no_signals(): void
    {
        $score = $this->engine->recalculate($this->teamId, 'https://acme.com', 'company');

        $this->assertEquals(0.0, $score->intent_score);
        $this->assertEquals(0.0, $score->engagement_score);
        $this->assertEquals(0, $score->signal_count);
    }

    /** @test */
    public function it_computes_higher_intent_score_for_purchase_intent_signals(): void
    {
        // Create a strong intent signal (purchase_intent category)
        $this->createSignal('https://acme.com', 'clearcue', [
            'signal_category' => 'purchase_intent',
            'signal_frequency' => 1,
        ]);

        // Create a weak intent signal (social category)
        $this->createSignal('https://beta.com', 'clearcue', [
            'signal_category' => 'social',
            'signal_frequency' => 1,
        ]);

        $strongScore = $this->engine->recalculate($this->teamId, 'https://acme.com', 'company');
        $weakScore = $this->engine->recalculate($this->teamId, 'https://beta.com', 'company');

        $this->assertGreaterThan($weakScore->intent_score, $strongScore->intent_score);
    }

    /** @test */
    public function it_applies_stacking_bonus_for_multiple_signals(): void
    {
        // One signal
        $this->createSignal('https://single.com', 'clearcue', ['signal_category' => 'evaluation']);

        // Multiple signals for the same entity
        for ($i = 0; $i < 4; $i++) {
            $this->createSignal('https://stacked.com', 'clearcue', ['signal_category' => 'evaluation', 'signal_frequency' => $i + 1]);
        }

        $singleScore = $this->engine->recalculate($this->teamId, 'https://single.com', 'company');
        $stackedScore = $this->engine->recalculate($this->teamId, 'https://stacked.com', 'company');

        $this->assertGreaterThan($singleScore->composite_score, $stackedScore->composite_score);
    }

    /** @test */
    public function it_applies_decay_so_old_signals_score_lower(): void
    {
        // Recent signal
        $recentSignal = $this->createSignal('https://acme.com', 'clearcue', ['signal_category' => 'evaluation']);

        // Old signal (30 days ago)
        $oldSignal = $this->createSignal('https://old.com', 'clearcue', ['signal_category' => 'evaluation']);
        $oldSignal->update(['received_at' => now()->subDays(30)]);

        $recentScore = $this->engine->recalculate($this->teamId, 'https://acme.com', 'company');
        $oldScore = $this->engine->recalculate($this->teamId, 'https://old.com', 'company');

        $this->assertGreaterThan($oldScore->intent_score, $recentScore->intent_score);
    }

    /** @test */
    public function it_correctly_classifies_intent_tags(): void
    {
        $score = new CompanyIntentScore;

        $score->composite_score = 85;
        $this->assertEquals('intent.hot', $score->intentTag());

        $score->composite_score = 65;
        $this->assertEquals('intent.warm', $score->intentTag());

        $score->composite_score = 35;
        $this->assertEquals('intent.lukewarm', $score->intentTag());

        $score->composite_score = 10;
        $this->assertEquals('intent.cold', $score->intentTag());
    }

    /** @test */
    public function it_persists_score_to_database(): void
    {
        $this->createSignal('https://acme.com', 'clearcue', ['signal_category' => 'evaluation']);

        $this->engine->recalculate($this->teamId, 'https://acme.com', 'company');

        $this->assertDatabaseHas('company_intent_scores', [
            'team_id' => $this->teamId,
            'entity_key' => 'https://acme.com',
            'entity_type' => 'company',
        ]);

        $record = CompanyIntentScore::where('entity_key', 'https://acme.com')->first();
        $this->assertNotNull($record->last_scored_at);
        $this->assertNotNull($record->recalculate_after);
        $this->assertGreaterThan(now(), $record->recalculate_after);
    }

    /** @test */
    public function it_upserts_rather_than_creating_duplicate_records(): void
    {
        $this->createSignal('https://acme.com', 'clearcue', ['signal_category' => 'research']);

        $this->engine->recalculate($this->teamId, 'https://acme.com', 'company');
        $this->engine->recalculate($this->teamId, 'https://acme.com', 'company');

        $this->assertEquals(1, CompanyIntentScore::where('entity_key', 'https://acme.com')->count());
    }

    /** @test */
    public function it_counts_signal_diversity_correctly(): void
    {
        $this->createSignal('https://multi.com', 'clearcue', ['signal_category' => 'social']);
        $this->createSignal('https://multi.com', 'github', ['signal_category' => 'evaluation']);
        $this->createSignal('https://multi.com', 'webhook', ['signal_category' => 'research']);

        $score = $this->engine->recalculate($this->teamId, 'https://multi.com', 'company');

        $this->assertEquals(3, $score->signal_count);
        $this->assertEquals(3, $score->signal_diversity);
    }

    private function createSignal(string $identifier, string $sourceType, array $payload = []): Signal
    {
        return Signal::create([
            'team_id' => $this->teamId,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
            'payload' => $payload,
            'content_hash' => hash('sha256', json_encode([$identifier, $sourceType, $payload, uniqid()])),
            'tags' => [],
            'received_at' => now(),
        ]);
    }
}
