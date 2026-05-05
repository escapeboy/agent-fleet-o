<?php

namespace Tests\Unit\Jobs;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\Telemetry\TracerProvider;
use App\Jobs\Middleware\ApplyTenantTracer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use ReflectionClass;
use Tests\TestCase;

class ApplyTenantTracerMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private ApplyTenantTracer $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ApplyTenantTracer;
    }

    private function seedTeamWithObservability(): Team
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'JobTeam',
            'slug' => 'job-team-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => ['observability' => [
                'enabled' => true,
                'endpoint' => 'https://job-team.example',
                'otlp_token_encrypted' => Crypt::encryptString('tok'),
            ]],
        ]);

        return $team;
    }

    private function peekOverrides(TracerProvider $provider): ?array
    {
        $r = new ReflectionClass($provider);
        $p = $r->getProperty('overrides');
        $p->setAccessible(true);

        return $p->getValue($provider);
    }

    public function test_public_team_id_property_is_resolved(): void
    {
        $team = $this->seedTeamWithObservability();
        $job = new class($team->id)
        {
            public function __construct(public readonly string $teamId) {}
        };

        $called = false;
        $this->middleware->handle($job, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $bound = app(TracerProvider::class);
        $this->assertSame('https://job-team.example', $this->peekOverrides($bound)['endpoint']);
    }

    public function test_team_id_method_is_used_when_property_absent(): void
    {
        $team = $this->seedTeamWithObservability();
        $job = new class($team->id)
        {
            public function __construct(private readonly string $_teamId) {}

            public function teamId(): string
            {
                return $this->_teamId;
            }
        };

        $this->middleware->handle($job, function () {});
        $bound = app(TracerProvider::class);
        $this->assertSame('https://job-team.example', $this->peekOverrides($bound)['endpoint']);
    }

    public function test_missing_team_context_leaves_platform_default_bound(): void
    {
        $originalBinding = app(TracerProvider::class);
        $job = new class
        {
            public string $irrelevant = 'noop';
        };

        $this->middleware->handle($job, function () {});

        // Platform default still bound — middleware didn't overwrite.
        $this->assertSame($originalBinding, app(TracerProvider::class));
    }

    public function test_empty_team_id_falls_through_to_platform_default(): void
    {
        $originalBinding = app(TracerProvider::class);
        $job = new class
        {
            public ?string $teamId = '';
        };

        $this->middleware->handle($job, function () {});
        $this->assertSame($originalBinding, app(TracerProvider::class));
    }

    public function test_next_closure_is_always_called(): void
    {
        $called = 0;
        $this->middleware->handle(
            new class
            {
                public string $teamId = '';
            },
            function () use (&$called) {
                $called++;
            },
        );
        $this->middleware->handle(
            new class
            {
                public string $teamId = 'ghost-uuid-not-in-db';
            },
            function () use (&$called) {
                $called++;
            },
        );
        $this->assertSame(2, $called);
    }

    public function test_factory_cache_reused_across_jobs_in_same_worker(): void
    {
        $team = $this->seedTeamWithObservability();
        $job = new class($team->id)
        {
            public function __construct(public readonly string $teamId) {}
        };

        $this->middleware->handle($job, function () {});
        $first = app(TracerProvider::class);

        // Reset the container binding to the default.
        app()->forgetInstance(TracerProvider::class);
        app()->singleton(TracerProvider::class);
        app(TracerProvider::class); // re-resolve default

        $this->middleware->handle($job, function () {});
        $second = app(TracerProvider::class);

        // Both resolve via the same singleton factory → same instance.
        $this->assertSame($first, $second);
    }
}
