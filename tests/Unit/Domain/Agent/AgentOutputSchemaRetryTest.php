<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Probes the private helpers added in Sprint 12:
 *   - resolveMaxRetries() — per-agent override vs config default vs clamp.
 *   - buildSchemaRetryPrompt() — error feedback + schema injection shape.
 *
 * The full retry loop is exercised end-to-end in the Agent execution
 * integration tests; here we lock down the decision logic that caps cost.
 */
class AgentOutputSchemaRetryTest extends TestCase
{
    use RefreshDatabase;

    private ExecuteAgentAction $action;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->agent = Agent::factory()->create(['team_id' => $team->id]);
        $this->action = app(ExecuteAgentAction::class);
    }

    private function invoke(string $method, array $args): mixed
    {
        $r = new ReflectionClass($this->action);
        $m = $r->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->action, ...$args);
    }

    public function test_resolve_max_retries_falls_back_to_config_default(): void
    {
        config(['agent.output_schema.max_retries_default' => 3]);
        $this->agent->output_schema_max_retries = null;

        $this->assertSame(3, $this->invoke('resolveMaxRetries', [$this->agent]));
    }

    public function test_resolve_max_retries_uses_per_agent_override(): void
    {
        $this->agent->output_schema_max_retries = 1;

        $this->assertSame(1, $this->invoke('resolveMaxRetries', [$this->agent]));
    }

    public function test_resolve_max_retries_zero_is_legitimate_opt_out(): void
    {
        config(['agent.output_schema.max_retries_default' => 2]);
        $this->agent->output_schema_max_retries = 0;

        $this->assertSame(0, $this->invoke('resolveMaxRetries', [$this->agent]));
    }

    public function test_resolve_max_retries_clamps_negative_to_zero(): void
    {
        $this->agent->output_schema_max_retries = -5;

        $this->assertSame(0, $this->invoke('resolveMaxRetries', [$this->agent]));
    }

    public function test_resolve_max_retries_clamps_above_five(): void
    {
        $this->agent->output_schema_max_retries = 99;

        $this->assertSame(5, $this->invoke('resolveMaxRetries', [$this->agent]));
    }

    public function test_build_retry_prompt_includes_errors_and_schema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'required' => true],
            ],
        ];

        $prompt = $this->invoke('buildSchemaRetryPrompt', [
            'previous string output',
            ['valid' => false, 'errors' => ['Missing required field: summary']],
            $schema,
        ]);

        $this->assertIsString($prompt);
        $this->assertStringContainsString('previous string output', $prompt);
        $this->assertStringContainsString('Missing required field: summary', $prompt);
        $this->assertStringContainsString('"type": "object"', $prompt);
        $this->assertStringContainsString('"summary"', $prompt);
    }

    public function test_build_retry_prompt_handles_multiple_errors(): void
    {
        $prompt = $this->invoke('buildSchemaRetryPrompt', [
            'x',
            ['valid' => false, 'errors' => ['err one', 'err two', 'err three']],
            ['type' => 'object'],
        ]);

        $this->assertStringContainsString('- err one', $prompt);
        $this->assertStringContainsString('- err two', $prompt);
        $this->assertStringContainsString('- err three', $prompt);
    }

    public function test_agent_migration_columns_are_fillable_and_cast(): void
    {
        $this->agent->update([
            'output_schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'output_schema_max_retries' => 4,
        ]);

        $fresh = $this->agent->fresh();
        $this->assertIsArray($fresh->output_schema);
        $this->assertSame(4, $fresh->output_schema_max_retries);
    }
}
