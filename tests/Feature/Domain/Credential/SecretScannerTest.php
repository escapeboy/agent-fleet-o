<?php

namespace Tests\Feature\Domain\Credential;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Credential\Jobs\CredentialScanJob;
use App\Domain\Credential\Services\SecretPatternLibrary;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecretScannerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->user->teams()->attach($this->team);
    }

    public function test_observer_dispatches_scan_job_when_agent_with_scannable_field_is_created(): void
    {
        Queue::fake();

        Agent::factory()->create([
            'team_id' => $this->team->id,
            'role' => 'You are an assistant. Use sk_live_abcdefghijklmnopqrstuvwx for billing.',
        ]);

        Queue::assertPushed(CredentialScanJob::class);
    }

    public function test_observer_does_not_dispatch_when_no_scannable_fields_change(): void
    {
        Queue::fake();

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'role' => 'Helpful assistant',
        ]);

        Queue::assertPushed(CredentialScanJob::class, 1); // once on create

        Queue::fake();

        // Update only a non-scannable field
        $agent->update(['name' => 'Renamed Agent']);

        Queue::assertNotPushed(CredentialScanJob::class);
    }

    public function test_scan_job_creates_audit_entry_on_finding(): void
    {
        $teamId = $this->team->id;
        $agentId = (string) Str::uuid();

        $job = new CredentialScanJob(
            teamId: $teamId,
            subjectType: 'agent',
            subjectId: $agentId,
            fields: ['role' => 'Use sk_live_abcdefghijklmnopqrstuvwx for payments'],
            contentHash: sha1('role=sk_live_abcdefghijklmnopqrstuvwx'),
        );

        $job->handle(new SecretPatternLibrary);

        $entry = AuditEntry::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('event', 'secret_detected')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals('agent', $entry->subject_type);
        $this->assertEquals($agentId, $entry->subject_id);
        $this->assertEquals('STRIPE_SECRET', $entry->properties['pattern_id']);
    }

    public function test_scan_job_skips_duplicate_content_hash(): void
    {
        $teamId = $this->team->id;
        $agentId = (string) Str::uuid();
        $hash = sha1('some-content');

        $job = new CredentialScanJob($teamId, 'agent', $agentId, ['role' => 'clean'], $hash);
        $job->handle(new SecretPatternLibrary);

        // Second call: cached → no audit entries created
        $job2 = new CredentialScanJob($teamId, 'agent', $agentId, ['role' => 'clean'], $hash);
        $job2->handle(new SecretPatternLibrary);

        $this->assertEquals(0, AuditEntry::withoutGlobalScopes()->where('event', 'secret_detected')->count());
    }

    public function test_clean_text_produces_no_audit_entries(): void
    {
        $job = new CredentialScanJob(
            teamId: $this->team->id,
            subjectType: 'skill',
            subjectId: (string) Str::uuid(),
            fields: ['system_prompt' => 'You are a helpful assistant that answers questions.'],
            contentHash: sha1('clean'),
        );

        $job->handle(new SecretPatternLibrary);

        $this->assertEquals(0, AuditEntry::withoutGlobalScopes()->where('event', 'secret_detected')->count());
    }
}
