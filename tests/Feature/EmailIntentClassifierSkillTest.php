<?php

namespace Tests\Feature;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use App\Domain\Trigger\Enums\TriggerRuleStatus;
use App\Domain\Trigger\Models\TriggerRule;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Database\Seeders\EmailSupportPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailIntentClassifierSkillTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_seeder_creates_email_intent_classifier_skill(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'email-intent-classifier')->first();

        $this->assertNotNull($skill);
        $this->assertEquals('Email Intent Classifier', $skill->name);
        $this->assertEquals(SkillType::Llm, $skill->type);
        $this->assertEquals(SkillStatus::Active, $skill->status);
        $this->assertFalse($skill->requires_approval);
    }

    public function test_email_intent_classifier_has_correct_input_schema(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'email-intent-classifier')->first();
        $inputSchema = $skill->input_schema;

        $this->assertEquals('object', $inputSchema['type']);
        $this->assertArrayHasKey('subject', $inputSchema['properties']);
        $this->assertArrayHasKey('body', $inputSchema['properties']);
        $this->assertArrayHasKey('sender_email', $inputSchema['properties']);
        $this->assertArrayHasKey('metadata', $inputSchema['properties']);
        $this->assertEquals(['subject', 'body'], $inputSchema['required']);
    }

    public function test_email_intent_classifier_has_correct_output_schema(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'email-intent-classifier')->first();
        $outputSchema = $skill->output_schema;

        $this->assertEquals('object', $outputSchema['type']);
        $this->assertArrayHasKey('primary_intent', $outputSchema['properties']);
        $this->assertArrayHasKey('confidence', $outputSchema['properties']);
        $this->assertArrayHasKey('urgency', $outputSchema['properties']);
        $this->assertArrayHasKey('summary', $outputSchema['properties']);
        $this->assertArrayHasKey('tags', $outputSchema['properties']);

        // Verify intent enum values
        $this->assertEquals(
            ['bug_report', 'feature_request', 'billing', 'general_inquiry'],
            $outputSchema['properties']['primary_intent']['enum'],
        );

        // Verify urgency enum values
        $this->assertEquals(
            ['low', 'medium', 'high'],
            $outputSchema['properties']['urgency']['enum'],
        );

        $this->assertContains('primary_intent', $outputSchema['required']);
        $this->assertContains('confidence', $outputSchema['required']);
        $this->assertContains('urgency', $outputSchema['required']);
    }

    public function test_email_intent_classifier_has_system_prompt(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'email-intent-classifier')->first();

        $this->assertNotEmpty($skill->system_prompt);
        $this->assertStringContainsString('bug_report', $skill->system_prompt);
        $this->assertStringContainsString('feature_request', $skill->system_prompt);
        $this->assertStringContainsString('billing', $skill->system_prompt);
        $this->assertStringContainsString('general_inquiry', $skill->system_prompt);
    }

    public function test_email_intent_classifier_has_low_temperature(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'email-intent-classifier')->first();

        $this->assertEquals(0.1, $skill->configuration['temperature']);
        $this->assertEquals(1024, $skill->configuration['max_tokens']);
    }

    public function test_email_intent_classifier_creates_initial_version(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'email-intent-classifier')->first();
        $version = SkillVersion::where('skill_id', $skill->id)->first();

        $this->assertNotNull($version);
        $this->assertEquals('1.0.0', $version->version);
        $this->assertEquals($skill->input_schema, $version->input_schema);
        $this->assertEquals($skill->output_schema, $version->output_schema);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);
        $this->seed(EmailSupportPipelineSeeder::class);

        $count = Skill::withoutGlobalScopes()->where('slug', 'email-intent-classifier')->count();
        $this->assertEquals(1, $count);
    }

    public function test_seeder_creates_support_classifier_agent_with_skill_attached(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $agent = Agent::withoutGlobalScopes()
            ->where('slug', 'support-classifier')
            ->first();

        $this->assertNotNull($agent);
        $this->assertEquals(AgentStatus::Active, $agent->status);

        $skill = $agent->skills()->where('slug', 'email-intent-classifier')->first();
        $this->assertNotNull($skill, 'Support classifier agent should have email-intent-classifier skill attached');
    }

    // ─── Workflow Tests ────────────────────────────────────────────

    public function test_seeder_creates_email_support_pipeline_workflow(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $workflow = Workflow::withoutGlobalScopes()->where('slug', 'email-support-pipeline')->first();

        $this->assertNotNull($workflow);
        $this->assertEquals('Email Support Pipeline', $workflow->name);
        $this->assertEquals(WorkflowStatus::Active, $workflow->status);
        $this->assertEquals($this->team->owner_id, $workflow->user_id);
    }

    public function test_workflow_has_five_nodes_in_correct_order(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $workflow = Workflow::withoutGlobalScopes()->where('slug', 'email-support-pipeline')->first();
        $nodes = WorkflowNode::where('workflow_id', $workflow->id)->orderBy('order')->get();

        $this->assertCount(5, $nodes);
        $this->assertEquals('start', $nodes[0]->type->value);
        $this->assertEquals('agent', $nodes[1]->type->value);
        $this->assertEquals('agent', $nodes[2]->type->value);
        $this->assertEquals('human_task', $nodes[3]->type->value);
        $this->assertEquals('end', $nodes[4]->type->value);
    }

    public function test_workflow_agent_nodes_reference_correct_agents(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $workflow = Workflow::withoutGlobalScopes()->where('slug', 'email-support-pipeline')->first();
        $nodes = WorkflowNode::where('workflow_id', $workflow->id)->orderBy('order')->get();

        $classifier = Agent::withoutGlobalScopes()->where('slug', 'support-classifier')->first();
        $drafter = Agent::withoutGlobalScopes()->where('slug', 'reply-drafter')->first();

        $this->assertEquals($classifier->id, $nodes[1]->agent_id);
        $this->assertEquals($drafter->id, $nodes[2]->agent_id);
    }

    public function test_workflow_has_four_edges(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $workflow = Workflow::withoutGlobalScopes()->where('slug', 'email-support-pipeline')->first();

        $this->assertEquals(4, $workflow->edges()->count());
    }

    public function test_workflow_human_task_node_has_form_schema(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $workflow = Workflow::withoutGlobalScopes()->where('slug', 'email-support-pipeline')->first();
        $humanTask = WorkflowNode::where('workflow_id', $workflow->id)
            ->where('type', 'human_task')
            ->first();

        $this->assertNotNull($humanTask);
        $this->assertArrayHasKey('form_schema', $humanTask->config);
        $this->assertArrayHasKey('sla_minutes', $humanTask->config);
        $this->assertEquals(30, $humanTask->config['sla_minutes']);

        $formSchema = $humanTask->config['form_schema'];
        $this->assertArrayHasKey('decision', $formSchema['properties']);
        $this->assertEquals(
            ['approve', 'approve_with_edits', 'reject'],
            $formSchema['properties']['decision']['enum'],
        );
    }

    public function test_workflow_seeder_is_idempotent(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);
        $this->seed(EmailSupportPipelineSeeder::class);

        $count = Workflow::withoutGlobalScopes()->where('slug', 'email-support-pipeline')->count();
        $this->assertEquals(1, $count);
    }

    // ─── Project & Trigger Rule Tests ─────────────────────────────

    public function test_seeder_creates_continuous_project_linked_to_workflow(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $project = Project::withoutGlobalScopes()
            ->where('title', 'Email Support Pipeline')
            ->first();

        $this->assertNotNull($project);
        $this->assertEquals(ProjectType::Continuous, $project->type);
        $this->assertEquals(ProjectStatus::Active, $project->status);
        $this->assertEquals($this->team->id, $project->team_id);

        $workflow = Workflow::withoutGlobalScopes()->where('slug', 'email-support-pipeline')->first();
        $this->assertEquals($workflow->id, $project->workflow_id);
    }

    public function test_seeder_creates_trigger_rule_for_imap_signals(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $rule = TriggerRule::withoutGlobalScopes()
            ->where('name', 'Email → Support Pipeline')
            ->first();

        $this->assertNotNull($rule);
        $this->assertEquals('imap', $rule->source_type);
        $this->assertEquals(TriggerRuleStatus::Active, $rule->status);
        $this->assertEquals(5, $rule->max_concurrent);

        $project = Project::withoutGlobalScopes()->where('title', 'Email Support Pipeline')->first();
        $this->assertEquals($project->id, $rule->project_id);
    }

    public function test_trigger_rule_has_input_mapping_for_email_fields(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $rule = TriggerRule::withoutGlobalScopes()
            ->where('name', 'Email → Support Pipeline')
            ->first();

        $this->assertArrayHasKey('subject', $rule->input_mapping);
        $this->assertArrayHasKey('body', $rule->input_mapping);
        $this->assertArrayHasKey('sender_email', $rule->input_mapping);
    }

    public function test_project_and_trigger_seeder_is_idempotent(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);
        $this->seed(EmailSupportPipelineSeeder::class);

        $projectCount = Project::withoutGlobalScopes()->where('title', 'Email Support Pipeline')->count();
        $ruleCount = TriggerRule::withoutGlobalScopes()->where('name', 'Email → Support Pipeline')->count();

        $this->assertEquals(1, $projectCount);
        $this->assertEquals(1, $ruleCount);
    }
}
