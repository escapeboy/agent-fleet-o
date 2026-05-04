<?php

namespace Tests\Feature\Domain\Project;

use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateProjectActionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_payload_is_deep_merged_with_existing(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->for($team)->create([
            'settings' => ['existing_key' => 'keep me', 'done_gate_enabled' => false],
        ]);

        app(UpdateProjectAction::class)->execute($project, [
            'title' => $project->title,
            'settings' => [
                'done_gate_enabled' => true,
                'done_gate_kill_switch' => true,
            ],
        ]);

        $project->refresh();
        $this->assertSame('keep me', $project->settings['existing_key']);
        $this->assertTrue($project->settings['done_gate_enabled']);
        $this->assertTrue($project->settings['done_gate_kill_switch']);
    }

    public function test_email_template_id_can_be_cleared(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->for($team)->create();

        app(UpdateProjectAction::class)->execute($project, [
            'title' => $project->title,
            'email_template_id' => null,
        ]);

        $project->refresh();
        $this->assertNull($project->email_template_id);
    }

    public function test_no_settings_payload_leaves_existing_untouched(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->for($team)->create([
            'settings' => ['done_gate_enabled' => true],
        ]);

        app(UpdateProjectAction::class)->execute($project, [
            'title' => 'New Title',
        ]);

        $project->refresh();
        $this->assertTrue($project->settings['done_gate_enabled']);
        $this->assertSame('New Title', $project->title);
    }
}
