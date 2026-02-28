<?php

namespace App\Domain\Metrics\Services;

/**
 * Signs and verifies tracking URLs to prevent metric manipulation.
 *
 * A short HMAC is embedded in generated tracking links so that
 * the TrackingController can reject unsigned or tampered requests
 * before recording any metrics.
 *
 * Usage:
 *   $signer = app(TrackingUrlSigner::class);
 *   $sig = $signer->sign('pixel', $experimentId, $outboundActionId);
 *   // append &sig={$sig} to the tracking URL
 *
 *   // In controller:
 *   $ok = $signer->verify($request->query('sig'), 'pixel', $experimentId, $outboundActionId);
 */
class TrackingUrlSigner
{
    // 16 hex chars = 64 bits of HMAC — enough to prevent guessing while keeping URLs short.
    private const SIG_LENGTH = 16;

    public function sign(string $type, ?string $experimentId, ?string $outboundActionId, ?string $url = null): string
    {
        $payload = implode(':', array_filter([$type, $experimentId, $outboundActionId, $url]));

        return substr(hash_hmac('sha256', $payload, $this->secret()), 0, self::SIG_LENGTH);
    }

    public function verify(string $sig, string $type, ?string $experimentId, ?string $outboundActionId, ?string $url = null): bool
    {
        $expected = $this->sign($type, $experimentId, $outboundActionId, $url);

        return hash_equals($expected, $sig);
    }

    private function secret(): string
    {
        return config('app.key');
    }
}
