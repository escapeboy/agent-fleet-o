<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 'api' limiter gives tokens carrying the explicit 'automation' ability a
 * higher per-minute budget, and every JSON 429 keeps its rate-limit headers.
 *
 * Throttling stays enabled here (unlike ApiTestCase), so cache stores are
 * flushed before each test to isolate the limiter counters.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make('cache');
        foreach (array_keys(config('cache.stores')) as $storeName) {
            try {
                $manager->store($storeName)->flush();
            } catch (\Throwable) {
                // Some stores may not be wired up in tests.
            }
        }

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Rate Limit Team',
            'slug' => 'rate-limit-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function token(array $abilities): string
    {
        return SanctumTokenIssuer::create($this->user, 'rate-limit', $abilities)->plainTextToken;
    }

    private function normalToken(): string
    {
        return $this->token(['team:'.$this->team->id]);
    }

    private function automationToken(): string
    {
        return $this->token(['team:'.$this->team->id, 'automation']);
    }

    private function me(string $token)
    {
        // The guard caches the resolved user (and its token) across requests in
        // one test; reset it so each request authenticates its own token.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me');
    }

    public function test_normal_token_gets_default_limit_header(): void
    {
        $this->me($this->normalToken())
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60');
    }

    public function test_automation_token_gets_higher_default_limit(): void
    {
        $this->me($this->automationToken())
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '240');
    }

    public function test_automation_limit_is_configurable(): void
    {
        config(['api.automation_rate_limit_per_minute' => 3]);
        $token = $this->automationToken();

        for ($i = 1; $i <= 3; $i++) {
            $this->me($token)->assertOk();
        }

        $this->me($token)->assertStatus(429);
    }

    public function test_normal_limit_is_configurable_and_separate_from_automation(): void
    {
        config([
            'api.rate_limit_per_minute' => 2,
            'api.automation_rate_limit_per_minute' => 5,
        ]);
        $normal = $this->normalToken();
        $automation = $this->automationToken();

        $this->me($normal)->assertOk();
        $this->me($normal)->assertOk();
        $this->me($normal)->assertStatus(429);

        $this->me($automation)->assertOk();
        $this->me($automation)->assertOk();
        $this->me($automation)->assertOk()->assertHeader('X-RateLimit-Limit', '5');
    }

    public function test_429_carries_retry_after_header(): void
    {
        config(['api.rate_limit_per_minute' => 1]);
        $token = $this->normalToken();

        $this->me($token)->assertOk();

        $response = $this->me($token)
            ->assertStatus(429)
            ->assertJsonPath('error', 'too_many_requests')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter);
        $this->assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);
    }

    public function test_wildcard_token_does_not_get_automation_limit(): void
    {
        $this->me($this->token(['*']))
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60');
    }
}
