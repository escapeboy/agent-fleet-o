<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Gateways\ClaudeCodeSettingsBuilder;
use App\Infrastructure\AI\Services\ClaudeCodeMcpConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaudeCodeSettingsBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $resolved = realpath(sys_get_temp_dir());
        $this->workdir = $resolved !== false ? $resolved : sys_get_temp_dir();
    }

    private function settingsBuilder(): ClaudeCodeSettingsBuilder
    {
        return new ClaudeCodeSettingsBuilder(new ClaudeCodeMcpConfigBuilder);
    }

    private function editPattern(): string
    {
        return '//'.ltrim(rtrim($this->workdir, '/'), '/').'/**';
    }

    public function test_agent_without_allow_shell_key_gets_shell_and_scoped_edits(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id, 'config' => []]);

        $settings = $this->settingsBuilder()->forAgent($agent, $this->workdir, null);
        $allow = $settings['permissions']['allow'];
        $deny = $settings['permissions']['deny'];

        foreach (['Read', 'Glob', 'Grep', 'Bash'] as $rule) {
            $this->assertContains($rule, $allow);
        }
        $this->assertContains('Edit('.$this->editPattern().')', $allow);
        $this->assertContains('Write('.$this->editPattern().')', $allow);
        $this->assertContains('Bash(sudo:*)', $deny);
    }

    public function test_allow_shell_false_removes_bash_but_keeps_sudo_deny(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id, 'config' => ['allow_shell' => false]]);

        $settings = $this->settingsBuilder()->forAgent($agent, $this->workdir, null);

        $this->assertNotContains('Bash', $settings['permissions']['allow']);
        $this->assertContains('Bash(sudo:*)', $settings['permissions']['deny']);
        $this->assertContains('Read', $settings['permissions']['allow']);
    }

    public function test_non_strict_false_allow_shell_keeps_bash(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id, 'config' => ['allow_shell' => 0]]);

        $settings = $this->settingsBuilder()->forAgent($agent, $this->workdir, null);

        $this->assertContains('Bash', $settings['permissions']['allow']);
    }

    public function test_mcp_servers_allowed_and_deny_list_tools_denied(): void
    {
        $denied = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'tool-secret',
            'type' => ToolType::McpHttp,
            'transport_config' => ['url' => 'https://mcp.example.test'],
            'credentials' => [],
        ]);
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'tool_deny_list' => [$denied->id],
        ]);

        $mcpConfig = ['mcpServers' => [
            'tool_alpha' => ['type' => 'http', 'url' => 'https://a.example.test/mcp'],
            'tool_secret' => ['type' => 'http', 'url' => 'https://mcp.example.test/mcp'],
        ]];

        $settings = $this->settingsBuilder()->forAgent($agent, $this->workdir, $mcpConfig);

        $this->assertContains('mcp__tool_alpha', $settings['permissions']['allow']);
        $this->assertNotContains('mcp__tool_secret', $settings['permissions']['allow']);
        $this->assertContains('mcp__tool_secret', $settings['permissions']['deny']);
    }

    public function test_deny_list_ignores_tools_of_other_teams(): void
    {
        $otherTool = Tool::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'slug' => 'foreign-tool',
            'type' => ToolType::McpHttp,
            'transport_config' => ['url' => 'https://mcp.example.test'],
            'credentials' => [],
        ]);
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'tool_deny_list' => [$otherTool->id],
        ]);

        $settings = $this->settingsBuilder()->forAgent($agent, $this->workdir, null);

        $this->assertNotContains('mcp__foreign_tool', $settings['permissions']['deny']);
    }

    public function test_build_without_working_directory_is_read_only_plus_mcp(): void
    {
        $settings = ClaudeCodeSettingsBuilder::build(null, ['mcpServers' => ['srv' => ['command' => 'x']]]);
        $allow = $settings['permissions']['allow'];

        $this->assertContains('mcp__srv', $allow);
        foreach ($allow as $rule) {
            $this->assertStringStartsNotWith('Edit', $rule);
            $this->assertStringStartsNotWith('Write', $rule);
        }
    }

    public function test_null_agent_uses_defaults(): void
    {
        $settings = $this->settingsBuilder()->forAgent(null, $this->workdir, null);

        $this->assertContains('Bash', $settings['permissions']['allow']);
        $this->assertSame(['Bash(sudo:*)'], $settings['permissions']['deny']);
    }

    public function test_cli_args_use_dont_ask_and_json_settings(): void
    {
        $settings = ClaudeCodeSettingsBuilder::build('/tmp', null);
        $args = ClaudeCodeSettingsBuilder::cliArgs($settings);

        $this->assertSame(['--permission-mode', 'dontAsk', '--settings'], array_slice($args, 0, 3));
        $this->assertSame($settings, json_decode($args[3], true));
    }
}
