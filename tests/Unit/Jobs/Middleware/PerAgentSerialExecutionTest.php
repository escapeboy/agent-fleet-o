<?php

namespace Tests\Unit\Jobs\Middleware;

use App\Jobs\Middleware\PerAgentSerialExecution;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class PerAgentSerialExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('locks')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('locks')->flushdb();

        parent::tearDown();
    }

    public function test_executes_job_when_no_agent_id(): void
    {
        $middleware = new PerAgentSerialExecution;
        $job = new FakeSerialJob(null);
        $called = false;

        $middleware->handle($job, function ($j) use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertNull($job->releasedWith);
    }

    public function test_acquires_lock_and_releases_after_execution(): void
    {
        $middleware = new PerAgentSerialExecution;
        $job = new FakeSerialJob('agent-1');
        $called = false;

        $middleware->handle($job, function ($j) use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertNull(Redis::connection('locks')->get('agent-exec:agent-1'));
        $this->assertNull($job->releasedWith);
    }

    public function test_releases_lock_even_when_inner_job_throws(): void
    {
        $middleware = new PerAgentSerialExecution;
        $job = new FakeSerialJob('agent-2');

        try {
            $middleware->handle($job, function ($j): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertNull(Redis::connection('locks')->get('agent-exec:agent-2'));
    }

    public function test_releases_job_with_backoff_when_lock_held(): void
    {
        Redis::connection('locks')->set('agent-exec:agent-3', '1', 'EX', 60);

        $middleware = new PerAgentSerialExecution;
        $job = new FakeSerialJob('agent-3', attempts: 1);
        $called = false;

        $middleware->handle($job, function ($j) use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertSame(10, $job->releasedWith);
    }

    public function test_uses_final_backoff_delay_after_schedule_exhausted(): void
    {
        Redis::connection('locks')->set('agent-exec:agent-4', '1', 'EX', 60);

        $middleware = new PerAgentSerialExecution;
        $job = new FakeSerialJob('agent-4', attempts: 10);

        $middleware->handle($job, function ($j): void {});

        $this->assertSame(120, $job->releasedWith);
    }
}

class FakeSerialJob
{
    public ?int $releasedWith = null;

    public ?\Throwable $failedWith = null;

    public function __construct(private ?string $agentId, private int $attempts = 1) {}

    public function getAgentId(): ?string
    {
        return $this->agentId;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function release(int $delay): void
    {
        $this->releasedWith = $delay;
    }

    public function fail(\Throwable $e): void
    {
        $this->failedWith = $e;
    }
}
