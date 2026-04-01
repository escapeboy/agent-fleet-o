<?php

namespace App\Domain\Assistant\AgentTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetSystemHealthTool implements Tool
{
    public function name(): string
    {
        return 'get_system_health';
    }

    public function description(): string
    {
        return 'Get system health status including queue, database, and cache connectivity';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $health = [];

        try {
            DB::select('SELECT 1');
            $health['database'] = 'ok';
        } catch (\Throwable) {
            $health['database'] = 'error';
        }

        try {
            Cache::store('redis')->put('health_check', true, 10);
            $health['cache'] = Cache::store('redis')->get('health_check') ? 'ok' : 'error';
        } catch (\Throwable) {
            $health['cache'] = 'error';
        }

        try {
            $horizonStatus = app('horizon.status')->current();
            $health['queue'] = $horizonStatus === 'running' ? 'ok' : $horizonStatus;
        } catch (\Throwable) {
            $health['queue'] = 'unknown';
        }

        return json_encode($health);
    }
}
