<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

/**
 * ClearCue GTM signal connector.
 *
 * Receives buyer intent signals pushed from ClearCue (https://clearcue.ai).
 * ClearCue monitors LinkedIn, job boards, news, conferences, and competitor
 * activity and pushes signals when companies show buying intent.
 *
 * Requires a Pro plan (€199/month) for webhook access.
 * Configure CLEARCUE_WEBHOOK_SECRET in .env.
 *
 * @see https://docs.clearcue.ai/integrations/webhooks
 */
class ClearCueConnector implements InputConnectorInterface
{
    /**
     * ClearCue signal categories and their scoring weights.
     * Based on the FIRE model (purchase intent → evaluation → research → weak indicator).
     */
    private const CATEGORY_SCORE_MAP = [
        'purchase_intent' => 1.0,
        'evaluation'      => 0.6,
        'research'        => 0.3,
        'hiring'          => 0.4,
        'social'          => 0.2,
        'events'          => 0.2,
        'news'            => 0.3,
        'weak_indicator'  => 0.1,
    ];

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Process a ClearCue webhook push payload.
     *
     * Config expects:
     *   'payload'  => array  (parsed JSON from ClearCue)
     *   'team_id'  => string (optional, for multi-tenant setups)
     *
     * ClearCue may deliver a single record or an array of records per push.
     * Each record contains: person, company, signal_context, list_id, detected_at.
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $teamId = $config['team_id'] ?? null;

        if (empty($payload)) {
            return [];
        }

        // ClearCue may send a batch array or a single record
        $records = isset($payload[0]) && is_array($payload[0])
            ? $payload
            : [$payload];

        $signals = [];

        foreach ($records as $record) {
            $signal = $this->processRecord($record, $teamId);
            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    public function supports(string $driver): bool
    {
        return $driver === 'clearcue';
    }

    /**
     * Validate ClearCue webhook HMAC-SHA256 signature.
     *
     * ClearCue sends the signature in the X-ClearCue-Signature header.
     * Note: Exact header name and format TBC from ClearCue developer docs
     * (requires Pro plan access). Update header name here once confirmed.
     */
    public static function validateSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if (empty($signatureHeader)) {
            return false;
        }

        // Strip optional "sha256=" prefix (some providers include it)
        $signature = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Process a single ClearCue signal record.
     *
     * Field mapping is intentionally lenient to handle ClearCue API schema updates.
     * Update field names here once real webhook payload is confirmed via ClearCue dashboard.
     *
     * @see https://docs.clearcue.ai/integrations/webhooks (requires Pro plan)
     */
    private function processRecord(array $record, ?string $teamId): ?Signal
    {
        $person = $record['person'] ?? [];
        $company = $record['company'] ?? [];
        $signalContext = $record['signal_context'] ?? [];
        $detectedAt = $record['detected_at'] ?? now()->toIso8601String();

        // Build a stable identifier: prefer LinkedIn URL (stable), fall back to company website
        $sourceIdentifier = $this->resolveIdentifier($person, $company);

        if (empty($sourceIdentifier)) {
            Log::warning('ClearCueConnector: no stable identifier in record', [
                'record_keys' => array_keys($record),
            ]);

            return null;
        }

        // Stable provider-assigned ID for deduplication (prevent re-delivery duplicates)
        $personId = $person['id'] ?? null;
        $sourceNativeId = $personId
            ? "clearcue:{$personId}"
            : null;

        // Build enriched payload — store full record for maximum context in TriggerRules
        $signalPayload = [
            // Person fields
            'person_id'         => $personId,
            'person_name'       => trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? '')),
            'person_position'   => $person['position'] ?? null,
            'person_seniority'  => $person['seniority'] ?? null,
            'person_linkedin'   => $person['linkedin_url'] ?? null,
            'person_about'      => $person['about_me'] ?? null,

            // Company fields
            'company_id'        => $company['id'] ?? null,
            'company_name'      => $company['company_name'] ?? $company['name'] ?? null,
            'company_domain'    => $this->extractDomain($company['website'] ?? $company['company_url'] ?? null),
            'company_website'   => $company['website'] ?? $company['company_url'] ?? null,
            'company_industry'  => $company['industry'] ?? null,
            'company_size'      => $company['company_size'] ?? null,
            'company_location'  => $company['location'] ?? null,
            'company_funding'   => $company['funding_stage'] ?? null,

            // Signal context
            'signal_type'       => $signalContext['signal_type'] ?? null,
            'signal_category'   => $signalContext['signal_category'] ?? null,
            'signal_frequency'  => $signalContext['signal_frequency'] ?? 1,
            'competitor'        => $signalContext['competitor_mentioned'] ?? null,
            'engagement_context' => $signalContext['engagement_context'] ?? null,
            'ai_insight'        => $signalContext['ai_qualified_insight'] ?? null,

            // Metadata
            'list_id'           => $record['list_id'] ?? null,
            'detected_at'       => $detectedAt,
            'source'            => 'clearcue',
        ];

        // Remove null values to keep payload clean
        $signalPayload = array_filter($signalPayload, fn ($v) => $v !== null);

        // Score based on signal category (0.1 – 1.0), boosted by frequency
        $category = $signalContext['signal_category'] ?? 'weak_indicator';
        $categoryScore = self::CATEGORY_SCORE_MAP[$category] ?? 0.1;
        $frequency = (int) ($signalContext['signal_frequency'] ?? 1);
        $score = min($categoryScore * (1 + 0.1 * ($frequency - 1)), 1.0);

        // Tags: always include 'intent', plus category and type for TriggerRule filtering
        $tags = array_values(array_filter(array_unique([
            'intent',
            'clearcue',
            $signalContext['signal_category'] ?? null,
            $signalContext['signal_type'] ?? null,
        ])));

        return $this->ingestAction->execute(
            sourceType: 'clearcue',
            sourceIdentifier: $sourceIdentifier,
            payload: $signalPayload,
            tags: $tags,
            sourceNativeId: $sourceNativeId,
            teamId: $teamId,
        );
    }

    /**
     * Resolve the most stable identifier for a person/company record.
     * LinkedIn URL is preferred as it is globally stable and unique.
     */
    private function resolveIdentifier(array $person, array $company): string
    {
        return $person['linkedin_url']
            ?? $company['website']
            ?? $company['company_url']
            ?? $company['linkedin_url']
            ?? '';
    }

    /**
     * Extract clean domain from a URL (e.g. "https://acme.com/foo" → "acme.com").
     */
    private function extractDomain(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host ?: null;
    }
}
