<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Artifact;
use App\Domain\Experiment\Models\ArtifactVersion;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ArtifactDownloadPoolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Download Pool Test',
            'slug' => 'dl-pool-test-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);

        // Reset pool counter before each test
        Redis::connection()->del('artifact:download:pool');
    }

    protected function tearDown(): void
    {
        Redis::connection()->del('artifact:download:pool');
        parent::tearDown();
    }

    private function createArtifact(): Artifact
    {
        $artifact = Artifact::create([
            'team_id' => $this->team->id,
            'name' => 'test-artifact',
            'type' => 'text',
        ]);

        ArtifactVersion::create([
            'artifact_id' => $artifact->id,
            'version' => 1,
            'content' => 'test content',
            'created_by_run_id' => null,
        ]);

        return $artifact;
    }

    public function test_download_succeeds_within_pool_limit(): void
    {
        config(['artifacts.max_concurrent_downloads' => 5]);

        $artifact = $this->createArtifact();

        $response = $this->actingAs($this->user)
            ->get("/api/v1/artifacts/{$artifact->id}/download");

        $response->assertSuccessful();
    }

    public function test_download_returns_429_when_pool_exhausted(): void
    {
        config(['artifacts.max_concurrent_downloads' => 2]);

        // Pre-fill the pool counter beyond limit
        Redis::connection()->set('artifact:download:pool', 3);

        $artifact = $this->createArtifact();

        $response = $this->actingAs($this->user)
            ->get("/api/v1/artifacts/{$artifact->id}/download");

        $response->assertStatus(429);
        $response->assertHeader('Retry-After', '5');
    }

    public function test_pool_counter_decrements_after_download(): void
    {
        config(['artifacts.max_concurrent_downloads' => 25]);

        $artifact = $this->createArtifact();

        $before = (int) (Redis::connection()->get('artifact:download:pool') ?? 0);

        $this->actingAs($this->user)
            ->get("/api/v1/artifacts/{$artifact->id}/download");

        $after = (int) (Redis::connection()->get('artifact:download:pool') ?? 0);

        $this->assertLessThanOrEqual($before + 1, $after);
    }
}
