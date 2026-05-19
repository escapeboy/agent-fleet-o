<?php

namespace App\Domain\Outbound\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Resend email API (https://resend.com).
 *
 * Stateless — the per-team API key is passed on every call so a single client
 * instance serves every tenant. No credentials are stored on this class.
 */
class ResendApiClient
{
    private const BASE_URL = 'https://api.resend.com';

    /**
     * Send a single transactional email through Resend.
     *
     * @param  array<string, mixed>  $payload  Resend email params (from, to, subject, html/text, headers…)
     * @return array{id: string}
     *
     * @throws \RuntimeException when the API rejects the request
     */
    public function sendEmail(string $apiKey, array $payload, ?string $idempotencyKey = null): array
    {
        $request = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(20);

        // Resend honours the Idempotency-Key header to dedupe retried sends.
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $response = $request->post(self::BASE_URL.'/emails', $payload);

        if ($response->failed()) {
            $message = $response->json('message') ?? $response->body();

            throw new \RuntimeException("Resend API error ({$response->status()}): {$message}");
        }

        return ['id' => (string) $response->json('id')];
    }
}
