<?php

namespace App\Mcp\Tools\System;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SystemHealthTool extends Tool
{
    protected string $name = 'system_health';

    protected string $description = 'Get system health status for database, cache (Redis), and queue (Horizon).';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $health = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
        ];

        return Response::text(json_encode($health));
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('SELECT 1');

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkCache(): string
    {
        try {
            $key = 'health_check_'.uniqid();
            Cache::store('redis')->put($key, 'ok', 10);
            $value = Cache::store('redis')->get($key);
            Cache::store('redis')->forget($key);

            return $value === 'ok' ? 'ok' : 'error';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkQueue(): string
    {
        try {
            $status = app('horizon.status')->current();

            return $status === 'running' ? 'ok' : $status;
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
