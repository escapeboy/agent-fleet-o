<?php

namespace Tests\Feature\Domain\Assistant;

use App\Domain\Assistant\Tools\BorunaTools;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class BorunaToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return MockInterface&AiGatewayInterface */
    private function mockGateway(): MockInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $this->app->instance(AiGatewayInterface::class, $gateway);

        return $gateway;
    }

    private function fakeResponse(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 0, completionTokens: 0, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 0,
        );
    }

    private function actAsTeamOwner(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Tools Team',
            'slug' => 'tools-team-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
    }

    public function test_tool_is_exposed_with_correct_name(): void
    {
        $tools = BorunaTools::tools();

        $this->assertCount(1, $tools);
        $this->assertEquals('generate_boruna_script', $tools[0]->name());
    }

    public function test_generates_script_via_gateway(): void
    {
        $this->actAsTeamOwner();
        $generatedAx = "fn main() -> Int {\n    42\n}";

        $this->mockGateway()
            ->shouldReceive('complete')
            ->once()
            ->andReturn($this->fakeResponse($generatedAx));

        $tool = BorunaTools::tools()[0];
        $result = $tool->handle('Return the answer to life');

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('Return the answer to life', $decoded['description']);
        $this->assertEquals($generatedAx, $decoded['script']);
    }

    public function test_strips_markdown_code_fence_from_model_output(): void
    {
        $this->actAsTeamOwner();
        $fenced = "```ax\nfn main() -> Int {\n    7\n}\n```";

        $this->mockGateway()
            ->shouldReceive('complete')
            ->once()
            ->andReturn($this->fakeResponse($fenced));

        $tool = BorunaTools::tools()[0];
        $result = $tool->handle('Return seven');
        $decoded = json_decode($result, true);

        $this->assertStringNotContainsString('```', $decoded['script']);
        $this->assertStringContainsString('fn main()', $decoded['script']);
    }

    public function test_passes_input_hint_through_to_user_message(): void
    {
        $this->actAsTeamOwner();
        $captured = null;

        $this->mockGateway()
            ->shouldReceive('complete')
            ->once()
            ->withArgs(function ($dto) use (&$captured) {
                $captured = $dto;

                return true;
            })
            ->andReturn($this->fakeResponse('fn main() -> String { "ok" }'));

        BorunaTools::tools()[0]->handle('Validate input', 'String');

        $this->assertNotNull($captured);
        $this->assertStringContainsString('Description:', $captured->userPrompt);
        $this->assertStringContainsString('Validate input', $captured->userPrompt);
        $this->assertStringContainsString('Input hint: String', $captured->userPrompt);
    }

    public function test_returns_structured_error_on_gateway_failure(): void
    {
        $this->actAsTeamOwner();

        $this->mockGateway()
            ->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException('provider unreachable'));

        $tool = BorunaTools::tools()[0];
        $result = $tool->handle('whatever');

        $decoded = json_decode($result, true);
        $this->assertEquals('generation_failed', $decoded['error']);
        $this->assertStringContainsString('provider unreachable', $decoded['message']);
    }

    public function test_uses_haiku_model(): void
    {
        $this->actAsTeamOwner();

        $this->mockGateway()
            ->shouldReceive('complete')
            ->once()
            ->withArgs(function ($dto) {
                return $dto->model === 'claude-haiku-4-5'
                    && $dto->purpose === 'boruna_script_generation';
            })
            ->andReturn($this->fakeResponse('fn main() -> Int { 0 }'));

        BorunaTools::tools()[0]->handle('noop');
    }
}
