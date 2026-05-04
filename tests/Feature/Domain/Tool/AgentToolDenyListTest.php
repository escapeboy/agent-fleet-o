<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentToolDenyListTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_in_deny_list_are_filtered_out(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $allowed = Tool::create([
            'team_id' => $team->id,
            'name' => 'allowed-tool',
            'slug' => 'allowed-tool-'.bin2hex(random_bytes(3)),
            'type' => ToolType::BuiltIn->value,
            'status' => ToolStatus::Active->value,
            'description' => 'allowed',
            'transport_config' => ['kind' => 'bash'],
        ]);
        $denied = Tool::create([
            'team_id' => $team->id,
            'name' => 'denied-tool',
            'slug' => 'denied-tool-'.bin2hex(random_bytes(3)),
            'type' => ToolType::BuiltIn->value,
            'status' => ToolStatus::Active->value,
            'description' => 'denied',
            'transport_config' => ['kind' => 'bash'],
        ]);
        $agent->tools()->attach([$allowed->id, $denied->id]);

        $agent->update(['tool_deny_list' => [$denied->id]]);

        $tools = app(ResolveAgentToolsAction::class)->execute($agent->refresh());
        $names = collect($tools)->map(fn ($t) => method_exists($t, 'name') ? $t->name() : null)->filter()->values()->all();

        $this->assertNotContains('denied-tool', $names);
    }

    public function test_empty_deny_list_does_not_filter(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create(['tool_deny_list' => null]);

        $tool = Tool::create([
            'team_id' => $team->id,
            'name' => 'normal-tool',
            'slug' => 'normal-tool-'.bin2hex(random_bytes(3)),
            'type' => ToolType::BuiltIn->value,
            'status' => ToolStatus::Active->value,
            'description' => 'd',
            'transport_config' => ['kind' => 'bash'],
        ]);
        $agent->tools()->attach($tool->id);

        $tools = app(ResolveAgentToolsAction::class)->execute($agent);
        $this->assertNotEmpty($tools);
    }
}
