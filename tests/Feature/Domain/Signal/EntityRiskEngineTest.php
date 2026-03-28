<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Approval\Actions\CreateSecurityReviewRequestAction;
use App\Domain\Shared\Models\ContactChannel;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Jobs\EvaluateContactRiskJob;
use App\Domain\Signal\Services\EntityRiskEngine;
use App\Infrastructure\Security\DTOs\IpReputationResult;
use App\Infrastructure\Security\IpReputationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;
use Tests\TestCase;

class EntityRiskEngineTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private EntityRiskEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-risk',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);

        // Stub IpReputationService to always return a clean result (no external calls).
        $this->app->instance(IpReputationService::class, new class extends IpReputationService
        {
            public function check(string $ip): IpReputationResult
            {
                return new IpReputationResult($ip, 0, false, false, 'US', false);
            }
        });

        $this->engine = app(EntityRiskEngine::class);
    }

    private function makeContact(array $attributes = []): ContactIdentity
    {
        return ContactIdentity::withoutGlobalScopes()->create(array_merge([
            'team_id' => $this->team->id,
            'display_name' => 'Test Contact',
            'email' => 'user@example.com',
            'phone' => null,
        ], $attributes));
    }

    public function test_clean_contact_has_zero_score(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->andReturn(false);

        $contact = $this->makeContact();

        // Add a verified channel so S02 (no verified channel) does not trigger.
        ContactChannel::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'contact_identity_id' => $contact->id,
            'channel' => 'email',
            'external_id' => 'user@example.com',
            'verified' => true,
        ]);

        $result = $this->engine->evaluate($contact);

        $this->assertEquals(0, $result['score']);
        $this->assertEmpty($result['flags']);

        $contact->refresh();
        $this->assertEquals(0, $contact->risk_score);
        $this->assertEmpty($contact->risk_flags);
        $this->assertNotNull($contact->risk_evaluated_at);
    }

    public function test_disposable_email_increases_score_by_20(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->andReturn(true);

        $contact = $this->makeContact(['email' => 'attacker@mailinator.com']);
        $result = $this->engine->evaluate($contact);

        $this->assertGreaterThanOrEqual(20, $result['score']);
        $ruleNames = array_column($result['flags'], 'rule');
        $this->assertContains('E01', $ruleNames);
    }

    public function test_no_contact_data_increases_score_by_10(): void
    {
        $contact = $this->makeContact(['email' => null, 'phone' => null]);
        $result = $this->engine->evaluate($contact);

        $ruleNames = array_column($result['flags'], 'rule');
        $this->assertContains('E02', $ruleNames);

        $flag = collect($result['flags'])->firstWhere('rule', 'E02');
        $this->assertEquals(10, $flag['weight']);
    }

    public function test_score_is_persisted_to_contact(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->andReturn(true);

        $contact = $this->makeContact(['email' => 'bad@mailinator.com', 'phone' => null]);
        $this->engine->evaluate($contact);

        $contact->refresh();
        $this->assertGreaterThan(0, $contact->risk_score);
        $this->assertNotEmpty($contact->risk_flags);
    }

    public function test_multiple_rules_accumulate_score(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->andReturn(true);

        // Triggers E01 (20) + E02 would not trigger since email is set, + S02 (10) since no verified channel
        $contact = $this->makeContact(['email' => 'bad@mailinator.com']);
        $result = $this->engine->evaluate($contact);

        // At minimum E01 (20) + S02 (10) = 30
        $this->assertGreaterThanOrEqual(30, $result['score']);
    }

    public function test_evaluate_contact_risk_job_dispatches_engine(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->andReturn(false);

        $contact = $this->makeContact();

        // Run the job synchronously
        (new EvaluateContactRiskJob($contact->id))->handle($this->engine, app(CreateSecurityReviewRequestAction::class));

        $contact->refresh();
        $this->assertNotNull($contact->risk_evaluated_at);
    }

    public function test_evaluate_contact_risk_job_is_unique_per_contact(): void
    {
        $contact = $this->makeContact();
        $job = new EvaluateContactRiskJob($contact->id);

        $this->assertEquals($contact->id, $job->uniqueId());
    }
}
