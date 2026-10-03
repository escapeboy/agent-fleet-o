<?php

namespace Tests\Feature\Domain\Assistant;

use App\Domain\Assistant\Agents\InjectTeamCredentialsMiddleware;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use RuntimeException;
use Tests\TestCase;

/**
 * laravel/ai 1.0 resolves and caches the provider before agent middleware
 * runs, so the team's key is applied around the whole call instead.
 */
class InjectTeamCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.anthropic.key' => 'platform-key']);

        $owner = User::factory()->create();
        $this->team = Team::create(['name' => 'Creds', 'slug' => 'creds-'.Str::lower(Str::random(6)), 'owner_id' => $owner->id, 'settings' => []]);
        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'anthropic',
            'credentials' => ['api_key' => 'team-key'],
            'is_active' => true,
        ]);
    }

    public function test_team_key_applies_during_the_call_and_is_restored_after(): void
    {
        $seen = app(InjectTeamCredentialsMiddleware::class)->around(
            $this->team->id,
            'anthropic',
            fn () => config('ai.providers.anthropic.key'),
        );

        $this->assertSame('team-key', $seen);
        $this->assertSame('platform-key', config('ai.providers.anthropic.key'));
    }

    public function test_key_is_restored_when_the_call_throws(): void
    {
        try {
            app(InjectTeamCredentialsMiddleware::class)->around($this->team->id, 'anthropic', fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $this->assertSame('platform-key', config('ai.providers.anthropic.key'));
    }

    public function test_cached_provider_instance_is_dropped_before_and_after(): void
    {
        $manager = app(AiManager::class);
        $before = $manager->textProvider('anthropic');

        $during = app(InjectTeamCredentialsMiddleware::class)->around($this->team->id, 'anthropic', fn () => $manager->textProvider('anthropic'));
        $after = $manager->textProvider('anthropic');

        $this->assertNotSame($before, $during, 'the run must not reuse an instance built with another key');
        $this->assertNotSame($during, $after, 'the next job must not reuse the team-key instance');
    }
}
