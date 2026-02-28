<?php

namespace App\Http\Controllers;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Models\Metric;
use App\Domain\Metrics\Services\TrackingUrlSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TrackingController extends Controller
{
    /**
     * Click tracking redirect.
     *
     * GET /api/track/click?url=<target>&exp=<experiment_id>&oa=<outbound_action_id>&utm_*=...
     */
    public function click(Request $request): RedirectResponse
    {
        $url = $request->query('url');
        $experimentId = $request->query('exp');
        $outboundActionId = $request->query('oa');

        if (! $url) {
            abort(422, 'Missing url parameter');
        }

        // Only allow http/https schemes to prevent javascript: and data: URI redirects
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            abort(422, 'Invalid redirect URL');
        }

        // Verify HMAC signature to prevent metric manipulation.
        // When TRACKING_REQUIRE_SIG=true, unsigned requests are rejected.
        // Default: false (log-only) for backwards compatibility with already-sent emails.
        if ($experimentId && config('services.tracking.require_sig', false)) {
            $sig = $request->query('sig', '');
            if (! $sig || ! app(TrackingUrlSigner::class)->verify($sig, 'click', $experimentId, $outboundActionId, $url)) {
                Log::warning('TrackingController: invalid or missing click signature', [
                    'experiment_id' => $experimentId,
                    'ip' => $request->ip(),
                ]);
                abort(403, 'Invalid tracking link');
            }
        }

        // Record click metric
        if ($experimentId) {
            try {
                $teamId = Experiment::withoutGlobalScopes()->where('id', $experimentId)->value('team_id');

                Metric::create([
                    'team_id' => $teamId,
                    'experiment_id' => $experimentId,
                    'outbound_action_id' => $outboundActionId,
                    'type' => 'click',
                    'value' => 1,
                    'source' => 'tracking',
                    'metadata' => array_filter([
                        'url' => $url,
                        'utm_source' => $request->query('utm_source'),
                        'utm_medium' => $request->query('utm_medium'),
                        'utm_campaign' => $request->query('utm_campaign'),
                        'utm_content' => $request->query('utm_content'),
                        'user_agent' => $request->userAgent(),
                        'ip' => $request->ip(),
                    ]),
                    'occurred_at' => now(),
                    'recorded_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error('TrackingController: Failed to record click', [
                    'error' => $e->getMessage(),
                    'experiment_id' => $experimentId,
                ]);
            }
        }

        return redirect()->away($url);
    }

    /**
     * 1x1 tracking pixel for email open tracking.
     *
     * GET /api/track/pixel?exp=<experiment_id>&oa=<outbound_action_id>
     */
    public function pixel(Request $request): Response
    {
        $experimentId = $request->query('exp');
        $outboundActionId = $request->query('oa');

        // Verify HMAC signature to prevent metric manipulation.
        if ($experimentId && config('services.tracking.require_sig', false)) {
            $sig = $request->query('sig', '');
            if (! $sig || ! app(TrackingUrlSigner::class)->verify($sig, 'pixel', $experimentId, $outboundActionId)) {
                Log::warning('TrackingController: invalid or missing pixel signature', [
                    'experiment_id' => $experimentId,
                    'ip' => $request->ip(),
                ]);
                // Return 404 on invalid signature — do not serve a pixel that
                // could be used to infer endpoint structure.
                abort(404);
            }
        }

        if ($experimentId) {
            try {
                $teamId = Experiment::withoutGlobalScopes()->where('id', $experimentId)->value('team_id');

                Metric::create([
                    'team_id' => $teamId,
                    'experiment_id' => $experimentId,
                    'outbound_action_id' => $outboundActionId,
                    'type' => 'open',
                    'value' => 1,
                    'source' => 'tracking_pixel',
                    'metadata' => array_filter([
                        'user_agent' => $request->userAgent(),
                        'ip' => $request->ip(),
                    ]),
                    'occurred_at' => now(),
                    'recorded_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error('TrackingController: Failed to record open', [
                    'error' => $e->getMessage(),
                    'experiment_id' => $experimentId,
                ]);
            }
        }

        // Return 1x1 transparent GIF
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => strlen($pixel),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
