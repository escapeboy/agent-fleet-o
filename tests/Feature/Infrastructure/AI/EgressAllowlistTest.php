<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\EgressAllowlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EgressAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private function request(?string $teamId): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: '',
            userPrompt: 'x',
            teamId: $teamId,
        );
    }

    public function test_upstream_hosts_are_lowercased_and_deduped(): void
    {
        $list = (new EgressAllowlist)->forRun(
            $this->request(null),
            ['api.anthropic.com', 'API.Anthropic.com', 'api.gh.com', ''],
        );

        $this->assertSame(['api.anthropic.com', 'api.gh.com'], $list);
    }

    public function test_team_egress_allowlist_is_merged(): void
    {
        $team = Team::factory()->create([
            'settings' => ['egress_allowlist' => ['extra.example.com', 'api.anthropic.com']],
        ]);

        $list = (new EgressAllowlist)->forRun(
            $this->request($team->id),
            ['api.anthropic.com'],
        );

        $this->assertContains('api.anthropic.com', $list);
        $this->assertContains('extra.example.com', $list);
        $this->assertSame(count($list), count(array_unique($list)), 'no duplicates after merge');
    }

    public function test_allows_is_exact_and_case_insensitive(): void
    {
        $allowlist = ['api.anthropic.com', 'api.gh.com'];
        $service = new EgressAllowlist;

        $this->assertTrue($service->allows($allowlist, 'API.ANTHROPIC.COM'));
        $this->assertFalse($service->allows($allowlist, 'evil.example.org'));
        $this->assertFalse($service->allows($allowlist, 'sub.api.anthropic.com'));
    }
}
