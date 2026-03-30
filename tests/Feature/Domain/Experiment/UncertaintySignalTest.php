<?php

namespace Tests\Feature\Domain\Experiment;

use App\Console\Commands\CheckHumanTaskSla;
use App\Domain\Experiment\Models\UncertaintySignal;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Experiment\UncertaintyEmitTool;
use App\Mcp\Tools\Experiment\UncertaintyResolveTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class UncertaintySignalTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-uncertainty',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        app()->instance('mcp.team_id', $this->team->id);
        $this->actingAs($this->user);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    // -------------------------------------------------------------------------
    // UncertaintyEmitTool
    // -------------------------------------------------------------------------

    public function test_emit_uncertainty_creates_signal(): void
    {
        $tool = new UncertaintyEmitTool;
        $request = new Request([
            'signal_text' => 'Should I use format A or format B?',
            'ttl_minutes' => 15,
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['signal_id']);

        $signal = UncertaintySignal::withoutGlobalScopes()->find($data['signal_id']);
        $this->assertNotNull($signal);
        $this->assertEquals('pending', $signal->status);
        $this->assertEquals('Should I use format A or format B?', $signal->signal_text);
        $this->assertEquals($this->team->id, $signal->team_id);
        $this->assertEquals(15, $signal->ttl_minutes);
    }

    public function test_emit_uncertainty_accepts_context_json(): void
    {
        $tool = new UncertaintyEmitTool;
        $context = ['key' => 'value', 'options' => ['a', 'b']];
        $request = new Request([
            'signal_text' => 'Ambiguous instruction detected.',
            'context_json' => json_encode($context),
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertTrue($data['success']);
        $signal = UncertaintySignal::withoutGlobalScopes()->find($data['signal_id']);
        $this->assertEquals($context, $signal->context);
    }

    public function test_emit_uncertainty_returns_error_for_invalid_context_json(): void
    {
        $tool = new UncertaintyEmitTool;
        $request = new Request([
            'signal_text' => 'Something unclear.',
            'context_json' => '{not valid json',
        ]);

        $response = $tool->handle($request);

        $this->assertStringContainsString('Invalid JSON', (string) $response->content());
    }

    public function test_emit_uncertainty_requires_team(): void
    {
        app()->forgetInstance('mcp.team_id');
        $this->user->update(['current_team_id' => null]);

        $tool = new UncertaintyEmitTool;
        $request = new Request(['signal_text' => 'Test']);

        $response = $tool->handle($request);

        $this->assertStringContainsString('No current team', (string) $response->content());
    }

    // -------------------------------------------------------------------------
    // UncertaintyResolveTool
    // -------------------------------------------------------------------------

    public function test_resolve_uncertainty_marks_signal_resolved(): void
    {
        $signal = UncertaintySignal::create([
            'team_id' => $this->team->id,
            'signal_text' => 'Original question',
            'status' => 'pending',
            'ttl_minutes' => 30,
        ]);

        $tool = new UncertaintyResolveTool;
        $request = new Request([
            'signal_id' => $signal->id,
            'resolution_note' => 'Use format A per specification 2.3.',
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertTrue($data['success']);
        $this->assertEquals('resolved', $data['status']);

        $signal->refresh();
        $this->assertEquals('resolved', $signal->status);
        $this->assertNotNull($signal->resolved_at);
        $this->assertEquals('Use format A per specification 2.3.', $signal->resolution_note);
        $this->assertEquals($this->user->id, $signal->resolved_by);
    }

    public function test_resolve_uncertainty_cannot_resolve_already_resolved_signal(): void
    {
        $signal = UncertaintySignal::create([
            'team_id' => $this->team->id,
            'signal_text' => 'Already resolved',
            'status' => 'resolved',
            'ttl_minutes' => 30,
            'resolved_at' => now(),
        ]);

        $tool = new UncertaintyResolveTool;
        $request = new Request(['signal_id' => $signal->id]);

        $response = $tool->handle($request);

        $this->assertStringContainsString("Cannot resolve signal with status 'resolved'", (string) $response->content());
    }

    public function test_resolve_uncertainty_returns_error_for_missing_signal(): void
    {
        $tool = new UncertaintyResolveTool;
        $request = new Request(['signal_id' => '00000000-0000-0000-0000-000000000000']);

        $response = $tool->handle($request);

        $this->assertStringContainsString('not found', (string) $response->content());
    }

    // -------------------------------------------------------------------------
    // UncertaintySignal::isExpired()
    // -------------------------------------------------------------------------

    public function test_is_expired_returns_false_for_fresh_signal(): void
    {
        $signal = UncertaintySignal::create([
            'team_id' => $this->team->id,
            'signal_text' => 'Fresh signal',
            'status' => 'pending',
            'ttl_minutes' => 30,
        ]);

        $this->assertFalse($signal->isExpired());
    }

    public function test_is_expired_returns_true_after_ttl(): void
    {
        $signal = UncertaintySignal::create([
            'team_id' => $this->team->id,
            'signal_text' => 'Old signal',
            'status' => 'pending',
            'ttl_minutes' => 1,
        ]);

        // Artificially age the signal
        $signal->forceFill(['created_at' => Carbon::now()->subMinutes(5)])->save();
        $signal->refresh();

        $this->assertTrue($signal->isExpired());
    }

    public function test_is_expired_returns_false_for_non_pending_signal(): void
    {
        $signal = UncertaintySignal::create([
            'team_id' => $this->team->id,
            'signal_text' => 'Resolved signal',
            'status' => 'resolved',
            'ttl_minutes' => 1,
        ]);

        $signal->forceFill(['created_at' => Carbon::now()->subMinutes(5)])->save();
        $signal->refresh();

        $this->assertFalse($signal->isExpired());
    }

    // -------------------------------------------------------------------------
    // CheckHumanTaskSla — uncertainty signal escalation
    // -------------------------------------------------------------------------

    public function test_sla_command_escalates_expired_uncertainty_signals(): void
    {
        $expiredSignal = UncertaintySignal::create([
            'team_id' => $this->team->id,
            'signal_text' => 'This should escalate',
            'status' => 'pending',
            'ttl_minutes' => 1,
        ]);
        $expiredSignal->forceFill(['created_at' => Carbon::now()->subMinutes(10)])->save();

        $freshSignal = UncertaintySignal::create([
            'team_id' => $this->team->id,
            'signal_text' => 'This is still fresh',
            'status' => 'pending',
            'ttl_minutes' => 60,
        ]);

        $this->artisan(CheckHumanTaskSla::class)->assertExitCode(0);

        $expiredSignal->refresh();
        $freshSignal->refresh();

        $this->assertEquals('escalated', $expiredSignal->status);
        $this->assertEquals('pending', $freshSignal->status);
    }
}
