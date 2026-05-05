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
 * Tests just the output-schema hook on ExecuteAgentAction. The full agent
 * execution flow has integration tests elsewhere; here we probe the
 * validateAgentOutput() method in isolation via reflection.
 */
class AgentOutputSchemaValidationTest extends TestCase
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
            'slug' => 't',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);

        $this->agent = Agent::factory()->create([
            'team_id' => $team->id,
        ]);

        $this->action = app(ExecuteAgentAction::class);
    }

    private function callValidate(Agent $agent, ?string $content): ?array
    {
        $r = new ReflectionClass($this->action);
        $m = $r->getMethod('validateAgentOutput');
        $m->setAccessible(true);

        return $m->invoke($this->action, $agent, $content);
    }

    public function test_returns_null_when_no_schema_configured(): void
    {
        $this->assertNull($this->callValidate($this->agent, 'anything'));
    }

    public function test_returns_null_when_schema_is_empty_array(): void
    {
        $this->agent->update(['output_schema' => []]);
        $this->assertNull($this->callValidate($this->agent->fresh(), 'anything'));
    }

    public function test_validates_json_payload_against_schema(): void
    {
        $this->agent->update(['output_schema' => [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'required' => true],
                'count' => ['type' => 'integer', 'required' => true],
            ],
        ]]);

        $good = $this->callValidate(
            $this->agent->fresh(),
            json_encode(['summary' => 'hi', 'count' => 3]),
        );
        $this->assertSame(['valid' => true, 'errors' => []], $good);
    }

    public function test_wraps_free_form_string_as_result_field(): void
    {
        $this->agent->update(['output_schema' => [
            'type' => 'object',
            'properties' => [
                'result' => ['type' => 'string', 'required' => true],
            ],
        ]]);

        $result = $this->callValidate($this->agent->fresh(), 'Just a plain answer.');
        $this->assertTrue($result['valid']);
    }

    public function test_flags_missing_required_field(): void
    {
        $this->agent->update(['output_schema' => [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'required' => true],
                'score' => ['type' => 'integer', 'required' => true],
            ],
        ]]);

        $result = $this->callValidate(
            $this->agent->fresh(),
            json_encode(['summary' => 'only one field']),
        );

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_malformed_json_falls_back_to_result_wrapper(): void
    {
        $this->agent->update(['output_schema' => [
            'type' => 'object',
            'properties' => [
                'result' => ['type' => 'string', 'required' => true],
            ],
        ]]);

        $result = $this->callValidate($this->agent->fresh(), 'not json {broken');
        $this->assertTrue($result['valid']);
    }

    public function test_null_content_wrapped_as_empty_result(): void
    {
        $this->agent->update(['output_schema' => [
            'type' => 'object',
            'properties' => [
                'result' => ['type' => 'string', 'required' => true],
            ],
        ]]);

        $result = $this->callValidate($this->agent->fresh(), null);
        $this->assertTrue($result['valid']);
    }

    public function test_agent_output_schema_is_cast_to_array(): void
    {
        $this->agent->update(['output_schema' => [
            'type' => 'object',
            'properties' => ['x' => ['type' => 'string']],
        ]]);

        $fresh = $this->agent->fresh();
        $this->assertIsArray($fresh->output_schema);
        $this->assertSame('object', $fresh->output_schema['type']);
    }
}
