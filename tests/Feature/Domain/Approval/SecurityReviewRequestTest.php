<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\CreateSecurityReviewRequestAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
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

class SecurityReviewRequestTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ContactIdentity $contact;

    private CreateSecurityReviewRequestAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Security Test Team',
            'slug' => 'security-test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);

        $this->contact = ContactIdentity::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'display_name' => 'Suspicious User',
            'email' => 'bad@mailinator.com',
            'phone' => null,
            'risk_score' => 40,
            'risk_flags' => [['rule' => 'E01', 'label' => 'Disposable email domain', 'weight' => 20]],
        ]);

        $this->action = app(CreateSecurityReviewRequestAction::class);
    }

    public function test_creates_security_review_request_for_high_risk_contact(): void
    {
        $review = $this->action->execute($this->contact);

        $this->assertNotNull($review);
        $this->assertEquals(ApprovalStatus::Pending, $review->status);
        $this->assertEquals('security_review', $review->context['type']);
        $this->assertEquals($this->contact->id, $review->context['entity_id']);
        $this->assertEquals(40, $review->context['risk_score']);
        $this->assertEquals($this->team->id, $review->team_id);
        $this->assertNotNull($review->expires_at);
    }

    public function test_does_not_create_duplicate_review_when_one_is_pending(): void
    {
        $first = $this->action->execute($this->contact);
        $second = $this->action->execute($this->contact);

        $this->assertNotNull($first);
        $this->assertNull($second);

        $this->assertEquals(1, ApprovalRequest::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereJsonContains('context->type', 'security_review')
            ->count());
    }

    public function test_creates_new_review_after_previous_one_is_resolved(): void
    {
        $first = $this->action->execute($this->contact);
        $first->update(['status' => ApprovalStatus::Approved]);

        $second = $this->action->execute($this->contact);

        $this->assertNotNull($second);
        $this->assertEquals(2, ApprovalRequest::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereJsonContains('context->type', 'security_review')
            ->count());
    }

    public function test_evaluate_job_auto_creates_security_review_above_threshold(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->andReturn(true);

        $this->app->instance(IpReputationService::class, new class extends IpReputationService
        {
            public function check(string $ip): IpReputationResult
            {
                return new IpReputationResult($ip, 0, false, false, 'US', false);
            }
        });

        // Fresh contact with no risk score yet
        $contact = ContactIdentity::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'display_name' => 'Fresh Suspect',
            'email' => 'attacker@mailinator.com',
        ]);

        config(['security.risk.review_threshold' => 20]);

        $engine = app(EntityRiskEngine::class);
        $reviewAction = app(CreateSecurityReviewRequestAction::class);

        $job = new EvaluateContactRiskJob($contact->id);
        $job->handle($engine, $reviewAction);

        $contact->refresh();
        $this->assertGreaterThanOrEqual(20, $contact->risk_score);

        $reviewCount = ApprovalRequest::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereJsonContains('context->type', 'security_review')
            ->where('status', ApprovalStatus::Pending->value)
            ->count();

        $this->assertEquals(1, $reviewCount);
    }

    public function test_is_security_review_method_on_model(): void
    {
        $review = $this->action->execute($this->contact);

        $this->assertTrue($review->isSecurityReview());
        $this->assertFalse($review->isHumanTask());
        $this->assertFalse($review->isCredentialReview());
    }
}
