<?php

namespace Tests\Unit\Infrastructure\Horizon;

use App\Infrastructure\Horizon\GuardedRedisSupervisorRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Tests\TestCase;

class GuardedRedisSupervisorRepositoryTest extends TestCase
{
    public function test_container_resolves_supervisor_repository_to_guarded_implementation(): void
    {
        $this->assertInstanceOf(
            GuardedRedisSupervisorRepository::class,
            $this->app->make(SupervisorRepository::class)
        );
    }

    public function test_get_ignores_non_array_pipeline_results_instead_of_throwing(): void
    {
        $repository = new class($this->app->make(RedisFactory::class)) extends GuardedRedisSupervisorRepository
        {
            protected function pipeline(callable $callback)
            {
                // Simulates Horizon's real pipeline() returning `false` for one
                // of the pipelined hmget commands (the condition that used to
                // crash RedisSupervisorRepository::get() with a TypeError).
                return [
                    false,
                    [
                        'name' => 'supervisor-1',
                        'master' => 'master-1',
                        'pid' => 123,
                        'status' => 'running',
                        'processes' => '{"redis:default":1}',
                        'options' => '{"timeout":60}',
                    ],
                ];
            }
        };

        $result = array_values($repository->get(['supervisor-1', 'supervisor-2']));

        $this->assertCount(1, $result);
        $this->assertSame('supervisor-1', $result[0]->name);
        $this->assertSame(['redis:default' => 1], $result[0]->processes);
    }
}
