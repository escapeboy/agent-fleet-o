<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\ClaudeCodeVpsConcurrencyCap;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ClaudeCodeVpsConcurrencyCapTest extends TestCase
{
    private ClaudeCodeVpsConcurrencyCap $cap;

    protected function setUp(): void
    {
        parent::setUp();

        config(['local_agents.vps.max_concurrency_per_team' => 2]);
        $this->cap = new ClaudeCodeVpsConcurrencyCap;

        Redis::connection('locks')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('locks')->flushdb();
        parent::tearDown();
    }

    public function test_first_acquire_returns_token(): void
    {
        $token = $this->cap->acquire('team-a');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_second_acquire_under_cap_succeeds(): void
    {
        $t1 = $this->cap->acquire('team-a');
        $t2 = $this->cap->acquire('team-a');

        $this->assertIsString($t1);
        $this->assertIsString($t2);
        $this->assertNotSame($t1, $t2);
    }

    public function test_acquire_at_cap_returns_false(): void
    {
        $this->cap->acquire('team-a');
        $this->cap->acquire('team-a');
        $result = $this->cap->acquire('team-a');

        $this->assertFalse($result);
    }

    public function test_release_frees_slot(): void
    {
        $t1 = $this->cap->acquire('team-a');
        $this->cap->acquire('team-a');
        $this->assertFalse($this->cap->acquire('team-a'));

        $this->cap->release('team-a', $t1);

        $this->assertIsString($this->cap->acquire('team-a'));
    }

    public function test_release_wrong_token_is_noop(): void
    {
        $this->cap->acquire('team-a');
        $this->cap->acquire('team-a');

        $this->cap->release('team-a', 'not-a-real-token');

        $this->assertFalse($this->cap->acquire('team-a'));
    }

    public function test_cap_is_per_team(): void
    {
        $this->cap->acquire('team-a');
        $this->cap->acquire('team-a');
        $this->assertFalse($this->cap->acquire('team-a'));

        $this->assertIsString($this->cap->acquire('team-b'));
        $this->assertIsString($this->cap->acquire('team-b'));
        $this->assertFalse($this->cap->acquire('team-b'));
    }

    public function test_active_count_reflects_acquires(): void
    {
        $this->assertSame(0, $this->cap->activeCount('team-c'));

        $this->cap->acquire('team-c');
        $this->assertSame(1, $this->cap->activeCount('team-c'));

        $t = $this->cap->acquire('team-c');
        $this->assertSame(2, $this->cap->activeCount('team-c'));

        $this->cap->release('team-c', $t);
        $this->assertSame(1, $this->cap->activeCount('team-c'));
    }

    public function test_cap_value_is_configurable(): void
    {
        config(['local_agents.vps.max_concurrency_per_team' => 3]);
        $cap = new ClaudeCodeVpsConcurrencyCap;

        $cap->acquire('team-d');
        $cap->acquire('team-d');
        $cap->acquire('team-d');
        $this->assertFalse($cap->acquire('team-d'));
    }
}
