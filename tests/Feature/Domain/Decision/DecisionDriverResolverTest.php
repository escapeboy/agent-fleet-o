<?php

namespace Tests\Feature\Domain\Decision;

use App\Domain\Decision\DTOs\ResolvedDecisionDriver;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use App\Domain\Decision\Services\DecisionDriverResolver;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DecisionDriverResolverTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();

        config()->set('decision.default', 'jev');
        config()->set('decision.drivers.jev', [
            'type' => 'system_one',
            'base_url' => 'https://api.example.test',
            'key' => 'platform-key',
            'model' => 'jev-1.13.0',
            'credential_provider' => 'typesafe',
            'credits_per_call' => 2,
        ]);
    }

    private function credential(Team $team, array $secrets, bool $active = true): TeamProviderCredential
    {
        return TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'typesafe',
            'name' => 'Typesafe',
            'credentials' => $secrets,
            'is_active' => $active,
        ]);
    }

    public function test_an_active_team_credential_wins_and_is_not_billable(): void
    {
        $this->credential($this->team, ['api_key' => 'team-key']);

        $resolved = app(DecisionDriverResolver::class)->resolve($this->team);

        $this->assertSame(ResolvedDecisionDriver::SOURCE_TEAM, $resolved->source);
        $this->assertSame(0, $resolved->creditsPerCall);
        $this->assertFalse($resolved->isBillable());
    }

    public function test_an_inactive_credential_falls_through_to_the_platform_key(): void
    {
        $this->credential($this->team, ['api_key' => 'team-key'], active: false);

        $resolved = app(DecisionDriverResolver::class)->resolve($this->team);

        $this->assertSame(ResolvedDecisionDriver::SOURCE_PLATFORM, $resolved->source);
        $this->assertTrue($resolved->isBillable());
    }

    public function test_platform_key_is_used_and_billed_when_the_team_has_none(): void
    {
        $resolved = app(DecisionDriverResolver::class)->resolve($this->team);

        $this->assertSame(ResolvedDecisionDriver::SOURCE_PLATFORM, $resolved->source);
        $this->assertSame(2, $resolved->creditsPerCall);
        $this->assertSame('jev', $resolved->name);
    }

    public function test_another_teams_credential_is_never_used(): void
    {
        $other = Team::factory()->create();
        $this->credential($other, ['api_key' => 'their-key']);

        $resolved = app(DecisionDriverResolver::class)->resolve($this->team);

        $this->assertSame(ResolvedDecisionDriver::SOURCE_PLATFORM, $resolved->source);
    }

    public function test_no_team_credential_and_no_platform_key_fails_loudly(): void
    {
        config()->set('decision.drivers.jev.key', '');

        $this->expectException(DecisionRequestException::class);
        $this->expectExceptionMessage('not available');

        app(DecisionDriverResolver::class)->resolve($this->team);
    }

    public function test_a_credential_with_no_usable_key_falls_through(): void
    {
        $this->credential($this->team, ['unrelated' => 'value']);

        $resolved = app(DecisionDriverResolver::class)->resolve($this->team);

        $this->assertSame(ResolvedDecisionDriver::SOURCE_PLATFORM, $resolved->source);
    }

    public function test_unknown_driver_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DecisionDriverResolver::class)->resolve($this->team, 'nope');
    }

    public function test_a_null_team_uses_the_platform_key(): void
    {
        $resolved = app(DecisionDriverResolver::class)->resolve(null);

        $this->assertSame(ResolvedDecisionDriver::SOURCE_PLATFORM, $resolved->source);
    }

    /**
     * Three of the five shipped drivers carry no `key` by design. An `llm`
     * driver authenticates through AiGatewayInterface, which resolves the
     * provider and meters the spend itself, so demanding a key here rejected
     * a driver that works.
     */
    public function test_an_llm_driver_resolves_without_a_platform_key(): void
    {
        config()->set('decision.drivers.haiku', [
            'type' => 'llm',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 2048,
            'temperature' => 0.0,
        ]);

        $resolved = app(DecisionDriverResolver::class)->resolve($this->team, 'haiku');

        $this->assertSame(ResolvedDecisionDriver::SOURCE_PLATFORM, $resolved->source);
        $this->assertSame(0, $resolved->creditsPerCall);
        $this->assertFalse($resolved->isBillable());
    }

    /**
     * A `cli` driver shells out to the local `claude` binary on that machine's
     * own subscription. There is nothing to meter, so no team-facing surface
     * may select one.
     */
    public function test_a_cli_driver_is_refused_to_teams(): void
    {
        config()->set('decision.drivers.claude_cli_haiku', [
            'type' => 'cli',
            'binary' => 'claude',
            'model' => 'claude-haiku-4-5',
        ]);

        $this->expectException(DecisionRequestException::class);
        $this->expectExceptionMessage('local CLI subscription');

        app(DecisionDriverResolver::class)->resolve($this->team, 'claude_cli_haiku');
    }
}
