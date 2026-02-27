<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Exceptions\SshFingerprintMismatchException;
use App\Domain\Tool\Services\SshHostFingerprintStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SshHostFingerprintStoreTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private SshHostFingerprintStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-ssh',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->store = new SshHostFingerprintStore;
    }

    public function test_first_connection_stores_fingerprint(): void
    {
        $this->store->verify($this->team->id, 'example.com', 22, 'abc123');

        $this->assertDatabaseHas('ssh_host_fingerprints', [
            'team_id' => $this->team->id,
            'host' => 'example.com',
            'port' => 22,
            'fingerprint_sha256' => 'abc123',
        ]);
    }

    public function test_same_fingerprint_passes_on_second_connection(): void
    {
        $this->store->verify($this->team->id, 'example.com', 22, 'abc123');
        // Second connection with same fingerprint — should not throw
        $this->store->verify($this->team->id, 'example.com', 22, 'abc123');

        $this->assertDatabaseCount('ssh_host_fingerprints', 1);
    }

    public function test_changed_fingerprint_raises_mismatch_exception(): void
    {
        $this->store->verify($this->team->id, 'example.com', 22, 'abc123');

        $this->expectException(SshFingerprintMismatchException::class);
        $this->store->verify($this->team->id, 'example.com', 22, 'different-fingerprint');
    }

    public function test_different_port_is_treated_as_separate_host(): void
    {
        $this->store->verify($this->team->id, 'example.com', 22, 'abc123');
        $this->store->verify($this->team->id, 'example.com', 2222, 'xyz789');

        $this->assertDatabaseCount('ssh_host_fingerprints', 2);
    }

    public function test_different_team_is_isolated(): void
    {
        $user2 = User::factory()->create();
        $team2 = Team::create([
            'name' => 'Team 2',
            'slug' => 'team-2-ssh',
            'owner_id' => $user2->id,
            'settings' => [],
        ]);

        $this->store->verify($this->team->id, 'example.com', 22, 'abc123');
        // Different team can store a different fingerprint for the same host
        $this->store->verify($team2->id, 'example.com', 22, 'xyz789');

        $this->assertDatabaseCount('ssh_host_fingerprints', 2);
    }
}
