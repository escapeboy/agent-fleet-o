<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\ClearCueConnector;
use App\Domain\Signal\Connectors\DatadogAlertConnector;
use App\Domain\Signal\Connectors\GitHubWebhookConnector;
use App\Domain\Signal\Connectors\JiraConnector;
use App\Domain\Signal\Connectors\LinearConnector;
use App\Domain\Signal\Connectors\PagerDutyConnector;
use App\Domain\Signal\Connectors\SentryAlertConnector;
use App\Domain\Signal\Connectors\SlackWebhookConnector;
use App\Domain\Signal\Connectors\WebhookConnector;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\SignalConnectorSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Per-team signal webhook receiver.
 *
 * POST /api/signals/{driver}/{teamId}
 *
 * Each team gets a unique URL per connector driver. The signing secret is stored
 * encrypted in signal_connector_settings (one row per team per driver).
 * Verification uses each provider's native signing format so that external services
 * (GitHub, Slack, Linear…) can be pointed at the per-team URL without any
 * custom header configuration.
 *
 * A 1-hour grace period accepts the previous secret during rotation.
 */
class PerTeamSignalWebhookController extends Controller
{
    /**
     * Supported drivers with their signature header names.
     * Each driver maps to: [header, signaturePrefix, connectorClass]
     * signaturePrefix is stripped before HMAC comparison (e.g. 'sha256=').
     */
    private const DRIVER_CONFIG = [
        'webhook' => [
            'header' => 'X-Webhook-Signature',
            'prefix' => '',
            'connector' => WebhookConnector::class,
        ],
        'github' => [
            'header' => 'X-Hub-Signature-256',
            'prefix' => 'sha256=',
            'connector' => GitHubWebhookConnector::class,
        ],
        'jira' => [
            'header' => 'X-Hub-Signature',
            'prefix' => 'sha256=',
            'connector' => JiraConnector::class,
        ],
        'linear' => [
            'header' => 'Linear-Signature',
            'prefix' => '',
            'connector' => LinearConnector::class,
        ],
        'sentry' => [
            'header' => 'Sentry-Hook-Signature',
            'prefix' => '',
            'connector' => SentryAlertConnector::class,
        ],
        'pagerduty' => [
            'header' => 'X-PagerDuty-Signature',
            'prefix' => '',
            'connector' => PagerDutyConnector::class,
        ],
        'clearcue' => [
            'header' => 'X-ClearCue-Signature',
            'prefix' => '',
            'connector' => ClearCueConnector::class,
        ],
        // Datadog uses header equality (not HMAC), handled separately
        'datadog' => [
            'header' => 'X-Datadog-Webhook-Secret',
            'prefix' => '',
            'connector' => DatadogAlertConnector::class,
        ],
        // Slack uses a custom signing format (v0:{timestamp}:{body}), handled separately
        'slack' => [
            'header' => 'X-Slack-Signature',
            'prefix' => '',
            'connector' => SlackWebhookConnector::class,
        ],
    ];

    /**
     * Handle an inbound per-team webhook.
     */
    public function __invoke(Request $request, string $driver, string $teamId): JsonResponse
    {
        $driverConfig = self::DRIVER_CONFIG[$driver] ?? null;

        if (! $driverConfig) {
            return response()->json(['error' => 'Unknown driver'], 404);
        }

        // Resolve the per-team setting without applying TeamScope (request is unauthenticated)
        $setting = SignalConnectorSetting::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('driver', $driver)
            ->where('is_active', true)
            ->first();

        if (! $setting) {
            return response()->json(['error' => 'Connector not configured'], 404);
        }

        $rawBody = $request->getContent();

        if (! $this->verifySignature($request, $rawBody, $driver, $driverConfig, $setting)) {
            Log::warning('PerTeamSignalWebhookController: Invalid signature', [
                'driver' => $driver,
                'team_id' => $teamId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        // Dispatch to the driver-specific connector for payload normalization
        /** @var InputConnectorInterface $connector */
        $connector = app($driverConfig['connector']);

        $config = array_merge(
            $this->buildConnectorConfig($request, $driver, $teamId),
            ['payload' => $payload],
        );

        $signals = $connector->poll($config);

        // Update activity tracking (non-blocking; failure does not affect response)
        try {
            $setting->increment('signal_count');
            $setting->update(['last_signal_at' => now()]);
        } catch (\Throwable) {
            // Non-critical
        }

        return response()->json([
            'ingested' => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }

    /**
     * Verify the request signature against the per-team secret (and grace-period previous secret).
     */
    private function verifySignature(
        Request $request,
        string $rawBody,
        string $driver,
        array $driverConfig,
        SignalConnectorSetting $setting,
    ): bool {
        $currentSecret = $setting->webhook_secret;

        if (! $currentSecret) {
            // No secret configured — reject all requests (fail closed)
            return false;
        }

        if ($this->checkSecret($request, $rawBody, $driver, $driverConfig, $currentSecret)) {
            return true;
        }

        // Grace period: try the previous secret if within 1 hour of rotation
        if ($setting->isPreviousSecretValid()) {
            return $this->checkSecret($request, $rawBody, $driver, $driverConfig, $setting->previous_webhook_secret);
        }

        return false;
    }

    /**
     * Run the driver-specific signature check for a given secret.
     */
    private function checkSecret(
        Request $request,
        string $rawBody,
        string $driver,
        array $driverConfig,
        string $secret,
    ): bool {
        return match ($driver) {
            // Slack uses v0:{timestamp}:{body} signing format
            'slack' => SlackWebhookConnector::validateSignature(
                $rawBody,
                $request->header('X-Slack-Request-Timestamp', ''),
                $request->header('X-Slack-Signature', ''),
                $secret,
            ),
            // Datadog sends the secret verbatim in a header (equality, not HMAC)
            'datadog' => hash_equals($secret, $request->header('X-Datadog-Webhook-Secret', '')),
            // GitHub/Jira: strip prefix before comparison
            'github' => GitHubWebhookConnector::validateSignature(
                $rawBody,
                $request->header($driverConfig['header'], ''),
                $secret,
            ),
            // All other drivers: connector-specific validateSignature (raw HMAC comparison)
            default => $this->validateHmac($rawBody, $request->header($driverConfig['header'], ''), $driverConfig['prefix'], $secret),
        };
    }

    /**
     * Generic HMAC validation: optionally strips a prefix, then does timing-safe compare.
     */
    private function validateHmac(string $rawBody, string $signatureHeader, string $prefix, string $secret): bool
    {
        if (empty($signatureHeader)) {
            return false;
        }

        $signature = $prefix ? ltrim($signatureHeader, $prefix) : $signatureHeader;
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Build the driver-specific config array passed to connector->poll().
     */
    private function buildConnectorConfig(Request $request, string $driver, string $teamId): array
    {
        $base = ['team_id' => $teamId];

        return match ($driver) {
            'github' => array_merge($base, ['event' => $request->header('X-GitHub-Event', '')]),
            'slack' => $base,
            default => array_merge($base, [
                'source' => $request->header('X-Webhook-Source', $request->ip()),
                'experiment_id' => $request->input('experiment_id'),
                'tags' => $request->input('tags', [$driver]),
                'files' => $request->allFiles(),
            ]),
        };
    }
}
