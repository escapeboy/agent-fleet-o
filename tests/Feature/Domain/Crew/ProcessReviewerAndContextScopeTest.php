<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Crew\Services\CrewOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessReviewerAndContextScopeTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Feature 2: ProcessReviewer & OutputReviewer enum cases
    // -------------------------------------------------------------------------

    public function test_process_reviewer_role_has_correct_label(): void
    {
        $this->assertSame('Process Reviewer', CrewMemberRole::ProcessReviewer->label());
    }

    public function test_process_reviewer_role_has_correct_color(): void
    {
        $this->assertSame('amber', CrewMemberRole::ProcessReviewer->color());
    }

    public function test_output_reviewer_role_has_correct_label(): void
    {
        $this->assertSame('Output Reviewer', CrewMemberRole::OutputReviewer->label());
    }

    public function test_output_reviewer_role_has_correct_color(): void
    {
        $this->assertSame('rose', CrewMemberRole::OutputReviewer->color());
    }

    public function test_process_reviewer_enum_value(): void
    {
        $this->assertSame('process_reviewer', CrewMemberRole::ProcessReviewer->value);
    }

    public function test_output_reviewer_enum_value(): void
    {
        $this->assertSame('output_reviewer', CrewMemberRole::OutputReviewer->value);
    }

    // -------------------------------------------------------------------------
    // Feature 4: context_scope column + filterContextForMember
    // -------------------------------------------------------------------------

    public function test_context_scope_column_can_be_set_and_retrieved_on_crew_member(): void
    {
        $crew = $this->makeCrewWithAgents();

        $member = CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'context_scope' => ['goal', 'dependency_outputs'],
        ]);

        $member->refresh();

        $this->assertIsArray($member->context_scope);
        $this->assertSame(['goal', 'dependency_outputs'], $member->context_scope);
    }

    public function test_context_scope_defaults_to_null(): void
    {
        $crew = $this->makeCrewWithAgents();

        $member = CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'context_scope' => null,
        ]);

        $member->refresh();

        $this->assertNull($member->context_scope);
    }

    public function test_filter_context_for_member_returns_full_context_when_scope_is_null(): void
    {
        $orchestrator = app(CrewOrchestrator::class);

        $context = [
            'goal' => 'Build a report',
            'dependency_outputs' => ['task_1' => 'done'],
            'metadata' => ['team' => 'marketing'],
        ];

        $filtered = $orchestrator->filterContextForMember($context, null);

        $this->assertSame($context, $filtered);
    }

    public function test_filter_context_for_member_returns_full_context_when_scope_is_empty(): void
    {
        $orchestrator = app(CrewOrchestrator::class);

        $context = [
            'goal' => 'Build a report',
            'dependency_outputs' => ['task_1' => 'done'],
        ];

        $filtered = $orchestrator->filterContextForMember($context, []);

        $this->assertSame($context, $filtered);
    }

    public function test_filter_context_for_member_filters_keys_when_scope_is_set(): void
    {
        $orchestrator = app(CrewOrchestrator::class);

        $context = [
            'goal' => 'Build a report',
            'dependency_outputs' => ['task_1' => 'done'],
            'metadata' => ['team' => 'marketing'],
            'secrets' => ['api_key' => 'sensitive'],
        ];

        $filtered = $orchestrator->filterContextForMember($context, ['goal', 'dependency_outputs']);

        $this->assertArrayHasKey('goal', $filtered);
        $this->assertArrayHasKey('dependency_outputs', $filtered);
        $this->assertArrayNotHasKey('metadata', $filtered);
        $this->assertArrayNotHasKey('secrets', $filtered);
        $this->assertCount(2, $filtered);
    }

    public function test_filter_context_for_member_returns_empty_array_when_no_keys_match_scope(): void
    {
        $orchestrator = app(CrewOrchestrator::class);

        $context = ['goal' => 'Build a report'];

        $filtered = $orchestrator->filterContextForMember($context, ['nonexistent_key']);

        $this->assertSame([], $filtered);
    }

    public function test_crew_member_factory_process_reviewer_state(): void
    {
        $crew = $this->makeCrewWithAgents();

        $member = CrewMember::factory()->processReviewer()->create(['crew_id' => $crew->id]);

        $this->assertSame(CrewMemberRole::ProcessReviewer, $member->role);
    }

    public function test_crew_member_factory_output_reviewer_state(): void
    {
        $crew = $this->makeCrewWithAgents();

        $member = CrewMember::factory()->outputReviewer()->create(['crew_id' => $crew->id]);

        $this->assertSame(CrewMemberRole::OutputReviewer, $member->role);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCrewWithAgents(): Crew
    {
        $coordinator = Agent::factory()->create();
        $qa = Agent::factory()->create();

        return Crew::factory()->create([
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
    }
}
