<?php

namespace Tests\Unit\Infrastructure\Bridge;

use App\Infrastructure\Bridge\BridgeRequestRegistry;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class BridgeRequestRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_pop_chunk_returns_null_on_a_clean_timeout(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('blpop')
            ->once()
            ->with(['bridge:stream:req-1'], 5)
            ->andReturn(null);

        Redis::shouldReceive('connection')->with('bridge')->once()->andReturn($connection);
        Redis::shouldReceive('purge')->never();

        $registry = new BridgeRequestRegistry;

        $this->assertNull($registry->popChunk('req-1', 5));
    }

    public function test_pop_chunk_reconnects_and_retries_after_a_redis_read_error(): void
    {
        $connection = Mockery::mock();
        $calls = 0;
        $connection->shouldReceive('blpop')
            ->twice()
            ->with(['bridge:stream:req-2'], 5)
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    throw new \RedisException('read error on connection to redis:6379');
                }

                return [
                    'bridge:stream:req-2',
                    json_encode(['chunk' => 'hello', 'done' => true, 'usage' => null]),
                ];
            });

        Redis::shouldReceive('connection')->with('bridge')->twice()->andReturn($connection);
        Redis::shouldReceive('purge')->with('bridge')->once();

        $registry = new BridgeRequestRegistry;

        $result = $registry->popChunk('req-2', 5);

        $this->assertSame(['chunk' => 'hello', 'done' => true, 'usage' => null], $result);
    }

    public function test_pop_chunk_returns_null_when_the_retry_also_fails(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('blpop')
            ->twice()
            ->with(['bridge:stream:req-3'], 5)
            ->andThrow(new \RedisException('read error on connection to redis:6379'));

        Redis::shouldReceive('connection')->with('bridge')->twice()->andReturn($connection);
        Redis::shouldReceive('purge')->with('bridge')->once();

        $registry = new BridgeRequestRegistry;

        $this->assertNull($registry->popChunk('req-3', 5));
    }
}
