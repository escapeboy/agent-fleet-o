<?php

namespace Tests\Feature\Livewire\Skills;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Livewire\Skills\CreateSkillForm;
use App\Livewire\Skills\SkillDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The type dropdown is generated from SkillType::cases(), but the validation
 * rule behind it is a hand-written `in:` list — a new case shows up in the UI
 * and is then rejected on submit until it is added to that list too.
 */
class CreateDecisionSkillFormTest extends TestCase
{
    use RefreshDatabase;

    private function actAsOwner(): Team
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        session(['current_team_id' => $team->id]);

        return $team;
    }

    /**
     * The playground posts the skill's prompt_template to the LLM gateway and
     * bills for it. A decision skill has no prompt_template, so the tab would
     * charge the team for an empty prompt and hand back prose.
     */
    public function test_the_playground_is_not_offered_for_a_decision_skill(): void
    {
        $team = $this->actAsOwner();

        $llm = Skill::factory()->create(['team_id' => $team->id, 'type' => SkillType::Llm->value]);
        $decision = Skill::factory()->create(['team_id' => $team->id, 'type' => SkillType::Decision->value]);

        Livewire::test(SkillDetailPage::class, ['skill' => $llm])
            ->assertSee('Playground');

        Livewire::test(SkillDetailPage::class, ['skill' => $decision])
            ->assertDontSee('Playground');
    }

    public function test_the_decision_type_passes_step_one_validation(): void
    {
        $this->actAsOwner();

        Livewire::test(CreateSkillForm::class)
            ->set('name', 'Triage decision')
            ->set('type', SkillType::Decision->value)
            ->set('riskLevel', 'low')
            ->call('nextStep')
            ->assertHasNoErrors(['type'])
            ->assertSet('step', 2);
    }

    public function test_a_blank_min_confidence_does_not_break_the_form(): void
    {
        $this->actAsOwner();

        Livewire::test(CreateSkillForm::class)
            ->set('name', 'No threshold')
            ->set('type', SkillType::Decision->value)
            ->set('riskLevel', 'low')
            ->set('decisionQuestions', '{"q":{"type":"choice"}}')
            ->set('decisionMinConfidence', '')
            ->call('save')
            ->assertHasNoErrors();

        $skill = Skill::where('name', 'No threshold')->firstOrFail();

        $this->assertArrayNotHasKey('min_confidence', $skill->configuration);
    }

    public function test_invalid_questions_json_is_rejected_at_save_time(): void
    {
        $this->actAsOwner();

        Livewire::test(CreateSkillForm::class)
            ->set('name', 'Broken')
            ->set('type', SkillType::Decision->value)
            ->set('riskLevel', 'low')
            ->set('decisionQuestions', '{not json')
            ->call('save')
            ->assertHasErrors('decisionQuestions');

        $this->assertNull(Skill::where('name', 'Broken')->first());
    }

    public function test_saving_a_decision_skill_stores_its_questions(): void
    {
        $this->actAsOwner();

        Livewire::test(CreateSkillForm::class)
            ->set('name', 'Triage decision')
            ->set('type', SkillType::Decision->value)
            ->set('riskLevel', 'low')
            ->set('decisionDriver', 'jev')
            ->set('decisionQuestions', '{"is_urgent":{"type":"choice","instructions":"Is this urgent?","criteria":["yes","no"]}}')
            ->set('decisionMinConfidence', 0.7)
            ->call('save')
            ->assertHasNoErrors();

        $skill = Skill::where('name', 'Triage decision')->firstOrFail();

        $this->assertSame(SkillType::Decision, $skill->type);
        $this->assertSame('jev', $skill->configuration['driver']);
        $this->assertSame(['yes', 'no'], $skill->configuration['questions']['is_urgent']['criteria']);
        $this->assertSame(0.7, $skill->configuration['min_confidence']);
    }
}
