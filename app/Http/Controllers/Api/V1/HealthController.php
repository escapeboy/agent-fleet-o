<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $checks = [];

        // Database
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'error';
        }

        // Redis
        try {
            app('redis')->connection()->ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable) {
            $checks['redis'] = 'error';
        }

        $allOk = ! in_array('error', $checks);

        return response()->json([
            'status' => $allOk ? 'healthy' : 'degraded',
            'checks' => $checks,
        ], $allOk ? 200 : 503);
    }
}
