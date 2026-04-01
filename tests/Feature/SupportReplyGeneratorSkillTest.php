<?php

namespace Tests\Feature;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use App\Models\User;
use Database\Seeders\EmailSupportPipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportReplyGeneratorSkillTest extends TestCase
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

    public function test_seeder_creates_support_reply_generator_skill(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();

        $this->assertNotNull($skill);
        $this->assertEquals('Support Reply Generator', $skill->name);
        $this->assertEquals(SkillType::Llm, $skill->type);
        $this->assertEquals(SkillStatus::Active, $skill->status);
        $this->assertEquals(RiskLevel::Medium, $skill->risk_level);
        $this->assertFalse($skill->requires_approval);
    }

    public function test_support_reply_generator_has_correct_input_schema(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();
        $inputSchema = $skill->input_schema;

        $this->assertEquals('object', $inputSchema['type']);
        $this->assertArrayHasKey('original_subject', $inputSchema['properties']);
        $this->assertArrayHasKey('original_body', $inputSchema['properties']);
        $this->assertArrayHasKey('sender_name', $inputSchema['properties']);
        $this->assertArrayHasKey('sender_email', $inputSchema['properties']);
        $this->assertArrayHasKey('classification', $inputSchema['properties']);
        $this->assertEquals(['original_subject', 'original_body', 'classification'], $inputSchema['required']);
    }

    public function test_support_reply_generator_classification_input_requires_key_fields(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();
        $classificationSchema = $skill->input_schema['properties']['classification'];

        $this->assertArrayHasKey('primary_intent', $classificationSchema['properties']);
        $this->assertArrayHasKey('urgency', $classificationSchema['properties']);
        $this->assertArrayHasKey('summary', $classificationSchema['properties']);
        $this->assertArrayHasKey('tags', $classificationSchema['properties']);
        $this->assertEquals(['primary_intent', 'urgency', 'summary'], $classificationSchema['required']);
    }

    public function test_support_reply_generator_has_correct_output_schema(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();
        $outputSchema = $skill->output_schema;

        $this->assertEquals('object', $outputSchema['type']);
        $this->assertArrayHasKey('reply_subject', $outputSchema['properties']);
        $this->assertArrayHasKey('reply_body', $outputSchema['properties']);
        $this->assertArrayHasKey('internal_notes', $outputSchema['properties']);
        $this->assertEquals(['reply_subject', 'reply_body'], $outputSchema['required']);
    }

    public function test_support_reply_generator_system_prompt_covers_all_intents(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();

        $this->assertNotEmpty($skill->system_prompt);
        $this->assertStringContainsString('bug_report', $skill->system_prompt);
        $this->assertStringContainsString('feature_request', $skill->system_prompt);
        $this->assertStringContainsString('billing', $skill->system_prompt);
        $this->assertStringContainsString('general_inquiry', $skill->system_prompt);
    }

    public function test_support_reply_generator_system_prompt_includes_fleetq_branding(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();

        $this->assertStringContainsString('FleetQ', $skill->system_prompt);
        $this->assertStringContainsString('fleetq.net', $skill->system_prompt);
        $this->assertStringContainsString('Powered by FleetQ AI Agents', $skill->system_prompt);
    }

    public function test_support_reply_generator_has_higher_temperature_than_classifier(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();

        $this->assertEquals(0.4, $skill->configuration['temperature']);
        $this->assertEquals(2048, $skill->configuration['max_tokens']);
    }

    public function test_support_reply_generator_creates_initial_version(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $skill = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->first();
        $version = SkillVersion::where('skill_id', $skill->id)->first();

        $this->assertNotNull($version);
        $this->assertEquals('1.0.0', $version->version);
        $this->assertEquals($skill->input_schema, $version->input_schema);
        $this->assertEquals($skill->output_schema, $version->output_schema);
    }

    public function test_seeder_is_idempotent_for_reply_generator(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);
        $this->seed(EmailSupportPipelineSeeder::class);

        $count = Skill::withoutGlobalScopes()->where('slug', 'support-reply-generator')->count();
        $this->assertEquals(1, $count);
    }

    public function test_seeder_creates_reply_drafter_agent_with_skill_attached(): void
    {
        $this->seed(EmailSupportPipelineSeeder::class);

        $agent = Agent::withoutGlobalScopes()
            ->where('slug', 'reply-drafter')
            ->first();

        $this->assertNotNull($agent);
        $this->assertEquals(AgentStatus::Active, $agent->status);
        $this->assertTrue($agent->constraints['requires_approval'] ?? false);

        $skill = $agent->skills()->where('slug', 'support-reply-generator')->first();
        $this->assertNotNull($skill, 'Reply drafter agent should have support-reply-generator skill attached');
    }
}
