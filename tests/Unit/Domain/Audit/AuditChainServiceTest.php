<?php

namespace Tests\Unit\Domain\Audit;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditChainServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditChainService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AuditChainService::class);
        config(['audit.hash_chain.settle_seconds' => 120]);
    }

    private function makeEntry(?string $teamId, int $minutesAgo = 10, array $properties = []): AuditEntry
    {
        return AuditEntry::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'event' => 'test.event',
            'properties' => $properties,
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_chains_entries_in_id_order_with_genesis_hash(): void
    {
        $team = Team::factory()->create();
        $first = $this->makeEntry($team->id, 30);
        $second = $this->makeEntry($team->id, 20);
        $third = $this->makeEntry($team->id, 10);

        $counts = $this->service->chainPending();

        $this->assertSame(3, $counts[$team->id]);

        $first->refresh();
        $second->refresh();
        $third->refresh();

        $this->assertSame(AuditChainService::GENESIS_HASH, $first->prev_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->entry_hash);
        $this->assertSame($first->entry_hash, $second->prev_hash);
        $this->assertSame($second->entry_hash, $third->prev_hash);
    }

    public function test_settle_window_skips_fresh_entries(): void
    {
        $team = Team::factory()->create();
        $settled = $this->makeEntry($team->id, 10);
        $fresh = $this->makeEntry($team->id, 0);

        $this->service->chainPending();

        $this->assertNotNull($settled->refresh()->entry_hash);
        $this->assertNull($fresh->refresh()->entry_hash);
    }

    public function test_each_team_and_platform_get_independent_chains(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $a = $this->makeEntry($teamA->id);
        $b = $this->makeEntry($teamB->id);
        $platform = $this->makeEntry(null);

        $counts = $this->service->chainPending();

        $this->assertSame(1, $counts[$teamA->id]);
        $this->assertSame(1, $counts[$teamB->id]);
        $this->assertSame(1, $counts['platform']);

        // Each chain starts at its own genesis — no cross-group linkage.
        $this->assertSame(AuditChainService::GENESIS_HASH, $a->refresh()->prev_hash);
        $this->assertSame(AuditChainService::GENESIS_HASH, $b->refresh()->prev_hash);
        $this->assertSame(AuditChainService::GENESIS_HASH, $platform->refresh()->prev_hash);
    }

    public function test_canonical_payload_is_key_order_independent(): void
    {
        $team = Team::factory()->create();
        $entry = $this->makeEntry($team->id, 10, ['beta' => 1, 'alpha' => ['z' => 1, 'a' => 2]]);

        $hashOriginal = $this->service->computeHash($entry, AuditChainService::GENESIS_HASH);

        $entry->properties = ['alpha' => ['a' => 2, 'z' => 1], 'beta' => 1];
        $hashReordered = $this->service->computeHash($entry, AuditChainService::GENESIS_HASH);

        $this->assertSame($hashOriginal, $hashReordered);
    }

    public function test_rerun_is_idempotent(): void
    {
        $team = Team::factory()->create();
        $entry = $this->makeEntry($team->id);

        $this->service->chainPending();
        $hash = $entry->refresh()->entry_hash;

        $counts = $this->service->chainPending();

        $this->assertSame([], $counts);
        $this->assertSame($hash, $entry->refresh()->entry_hash);
    }

    public function test_hash_chain_is_disabled_by_default(): void
    {
        // The schedule block in routes/console.php is gated on this flag;
        // a fresh install must not start chaining without an explicit opt-in.
        $this->assertFalse((bool) config('audit.hash_chain.enabled'));
    }
}
