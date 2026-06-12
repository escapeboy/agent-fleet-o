<?php

namespace Tests\Feature\Domain\Audit;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\System\AuditChainVerifyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Tests\TestCase;

class AuditChainVerifyTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.hash_chain.settle_seconds' => 120]);
        $this->team = Team::factory()->create();
    }

    private function makeEntry(int $minutesAgo = 10, array $properties = [], ?string $id = null): AuditEntry
    {
        $attributes = [
            'team_id' => $this->team->id,
            'event' => 'test.event',
            'properties' => $properties,
            'created_at' => now()->subMinutes($minutesAgo),
        ];

        if ($id !== null) {
            // 'id' is guarded; forceFill so the crafted UUID survives.
            $entry = (new AuditEntry)->forceFill([...$attributes, 'id' => $id]);
            $entry->save();

            return $entry;
        }

        return AuditEntry::withoutGlobalScopes()->create($attributes);
    }

    private function chainAll(): void
    {
        $this->artisan('audit:chain')->assertSuccessful();
    }

    public function test_verify_passes_on_intact_chain(): void
    {
        $this->makeEntry(30, ['a' => 1]);
        $this->makeEntry(20, ['b' => 2]);
        $this->chainAll();

        $this->artisan('audit:verify-chain')->assertSuccessful();
    }

    public function test_detects_mutated_property_on_chained_entry(): void
    {
        $this->makeEntry(30, ['amount' => 100]);
        $victim = $this->makeEntry(20, ['amount' => 200]);
        $this->makeEntry(10, ['amount' => 300]);
        $this->chainAll();

        DB::table('audit_entries')->where('id', $victim->id)
            ->update(['properties' => json_encode(['amount' => 999999])]);

        $this->artisan('audit:verify-chain')->assertFailed();

        $report = app(AuditChainService::class)->verifyChain($this->team->id)[0];
        $this->assertSame('broken', $report['status']);
        $this->assertSame($victim->id, $report['first_break_id']);
    }

    public function test_detects_deleted_middle_entry(): void
    {
        $this->makeEntry(30);
        $middle = $this->makeEntry(20);
        $this->makeEntry(10);
        $this->chainAll();

        DB::table('audit_entries')->where('id', $middle->id)->delete();

        $this->artisan('audit:verify-chain', ['--team' => $this->team->id])->assertFailed();
    }

    public function test_retention_cleanup_of_oldest_entries_does_not_break_verification(): void
    {
        $oldest = $this->makeEntry(60);
        $this->makeEntry(40);
        $this->makeEntry(20);
        $this->chainAll();

        // audit:cleanup deletes oldest rows first; the oldest retained row becomes the anchor.
        DB::table('audit_entries')->where('id', $oldest->id)->delete();

        $this->artisan('audit:verify-chain', ['--team' => $this->team->id])->assertSuccessful();
    }

    public function test_unchained_straggler_below_cursor_warns_but_does_not_fail(): void
    {
        $this->makeEntry(30);
        $this->makeEntry(20);
        $this->chainAll();

        // Simulate a transaction that committed after its UUIDv7 range was sealed.
        $this->makeEntry(10, [], '00000000-0000-7000-8000-000000000001');

        $this->artisan('audit:verify-chain', ['--team' => $this->team->id])->assertSuccessful();

        $report = app(AuditChainService::class)->verifyChain($this->team->id)[0];
        $this->assertSame('ok', $report['status']);
        $this->assertSame(1, $report['unchained_stragglers']);
    }

    public function test_mcp_tool_reports_only_callers_team_chain(): void
    {
        $otherTeam = Team::factory()->create();

        $mine = $this->makeEntry(30);
        $foreign = AuditEntry::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'event' => 'test.event',
            'created_at' => now()->subMinutes(30),
        ]);
        $this->chainAll();

        // Tamper with the OTHER team's chain — caller's report must stay ok.
        DB::table('audit_entries')->where('id', $foreign->id)
            ->update(['properties' => json_encode(['injected' => true])]);

        app()->instance('mcp.team_id', $this->team->id);

        $response = (new AuditChainVerifyTool)->handle(new Request([]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError());
        $this->assertSame($this->team->id, $payload['report']['group']);
        $this->assertSame('ok', $payload['report']['status']);
        $this->assertSame(1, $payload['report']['checked']);
        $this->assertNotNull($mine->refresh()->entry_hash);
    }
}
