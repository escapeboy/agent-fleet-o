<?php

namespace App\Infrastructure\Horizon;

use Laravel\Horizon\Repositories\RedisSupervisorRepository;

/**
 * Laravel\Horizon\Repositories\RedisSupervisorRepository::get() calls
 * array_values() on every pipelined `hmget` result without checking that the
 * result is actually an array. A transient Redis hiccup (or a pipelined
 * command failing independently of the others) can make one of those results
 * `false`, which throws `TypeError: array_values(): Argument #1 ($array)
 * must be of type array, false given`. The sibling
 * RedisMasterSupervisorRepository already guards against this with an
 * `is_array()` check; this override applies the same guard here.
 */
class GuardedRedisSupervisorRepository extends RedisSupervisorRepository
{
    public function get(array $names)
    {
        $records = $this->pipeline(function ($pipe) use ($names) {
            foreach ($names as $name) {
                $pipe->hmget('supervisor:'.$name, ['name', 'master', 'pid', 'status', 'processes', 'options']);
            }
        });

        return collect($records)
            ->filter(fn ($record) => is_array($record))
            ->map(function ($record) {
                $record = array_values($record);

                return ! $record[0] ? null : (object) [
                    'name' => $record[0],
                    'master' => $record[1],
                    'pid' => $record[2],
                    'status' => $record[3],
                    'processes' => json_decode($record[4], true),
                    'options' => json_decode($record[5], true),
                ];
            })
            ->filter()
            ->all();
    }
}
