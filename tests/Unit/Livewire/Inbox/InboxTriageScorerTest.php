<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Inbox;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Livewire\Inbox\Services\InboxTriageScorer;
use Carbon\Carbon;
use Tests\TestCase;

class InboxTriageScorerTest extends TestCase
{
    private InboxTriageScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new InboxTriageScorer;
    }

    private function makeApproval(array $overrides = []): ApprovalRequest
    {
        $a = new ApprovalRequest;
        $a->fill(array_merge([
            'team_id' => 'team-1',
            'status' => ApprovalStatus::Pending,
            'context' => [],
        ], $overrides));
        $a->id = 'fake-id';
        $a->created_at = $overrides['created_at'] ?? now();

        return $a;
    }

    private function makeProposal(array $overrides = []): OutboundProposal
    {
        $p = new OutboundProposal;
        $p->fill(array_merge([
            'team_id' => 'team-1',
            'channel' => OutboundChannel::Email,
            'target' => ['address' => 'a@example.com'],
            'content' => [],
            'risk_score' => 0.5,
            'status' => OutboundProposalStatus::PendingApproval,
        ], $overrides));
        $p->id = 'fake-id';
        $p->created_at = $overrides['created_at'] ?? now();

        return $p;
    }

    public function test_score_in_zero_one_range(): void
    {
        $score = $this->scorer->scoreApproval($this->makeApproval());
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_security_review_scores_higher_than_baseline(): void
    {
        $baseline = $this->scorer->scoreApproval($this->makeApproval());
        $security = $this->scorer->scoreApproval($this->makeApproval([
            'context' => ['type' => 'security_review'],
        ]));

        $this->assertGreaterThan($baseline, $security);
    }

    public function test_past_sla_deadline_increases_score_significantly(): void
    {
        $baseline = $this->scorer->scoreApproval($this->makeApproval());
        $expired = $this->scorer->scoreApproval($this->makeApproval([
            'sla_deadline' => Carbon::now()->subHour(),
        ]));

        $this->assertGreaterThanOrEqual(0.4, $expired);
        $this->assertGreaterThan($baseline, $expired);
    }

    public function test_high_risk_proposal_scores_higher_than_low_risk(): void
    {
        $low = $this->scorer->scoreProposal($this->makeProposal(['risk_score' => 0.1]));
        $high = $this->scorer->scoreProposal($this->makeProposal(['risk_score' => 0.95]));

        $this->assertGreaterThan($low, $high);
    }

    public function test_recommendation_categorizes_score_into_three_buckets(): void
    {
        $this->assertSame('review_now', $this->scorer->recommendation(0.85));
        $this->assertSame('review_now', $this->scorer->recommendation(0.70));
        $this->assertSame('review_soon', $this->scorer->recommendation(0.50));
        $this->assertSame('review_soon', $this->scorer->recommendation(0.40));
        $this->assertSame('low_priority', $this->scorer->recommendation(0.39));
        $this->assertSame('low_priority', $this->scorer->recommendation(0.0));
    }

    public function test_recommendation_label_and_color_lookup(): void
    {
        $this->assertSame('Review now', $this->scorer->recommendationLabel('review_now'));
        $this->assertSame('Review soon', $this->scorer->recommendationLabel('review_soon'));
        $this->assertSame('Low priority', $this->scorer->recommendationLabel('low_priority'));

        $this->assertSame('red', $this->scorer->recommendationColor('review_now'));
        $this->assertSame('amber', $this->scorer->recommendationColor('review_soon'));
        $this->assertSame('gray', $this->scorer->recommendationColor('low_priority'));
    }

    public function test_old_pending_item_gets_age_boost(): void
    {
        $fresh = $this->scorer->scoreApproval($this->makeApproval(['created_at' => Carbon::now()]));
        $stale = $this->scorer->scoreApproval($this->makeApproval(['created_at' => Carbon::now()->subDays(5)]));

        $this->assertGreaterThan($fresh, $stale);
    }
}
