<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * @tags Broadcasting
 *
 * Returns the public-facing Reverb (Pusher protocol) connection parameters
 * for clients that don't go through `bridge/register` — primarily the
 * desktop app. Mirrors what `BridgeController::register` already embeds in
 * its registration response so both code paths converge on one config
 * source.
 *
 * Resolution mirrors `BridgeController::buildReverbUrl()`: prefer
 * `app.reverb_public_*` (set per public-facing deployment) and fall back to
 * the package-level `reverb.apps.apps.0.options.*`. Internal-only Docker
 * hosts in `REVERB_HOST` never leak.
 */
class BroadcastingConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $scheme = config('app.reverb_public_scheme')
            ?: config('reverb.apps.apps.0.options.scheme', 'https');
        $host = config('app.reverb_public_host')
            ?: config('reverb.apps.apps.0.options.host');
        $port = config('app.reverb_public_port')
            ?: (int) config('reverb.apps.apps.0.options.port', 443);

        return response()->json([
            'data' => [
                'app_key' => config('reverb.apps.apps.0.key'),
                'host' => $host,
                'port' => (int) $port,
                'scheme' => $scheme,
            ],
        ]);
    }
}
