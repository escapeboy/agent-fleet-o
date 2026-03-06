<?php

namespace App\Domain\Email\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MjmlRenderer
{
    /**
     * Render MJML markup to inlined HTML.
     *
     * Uses a self-hosted mjml-http-server when MJML_SERVER_URL is configured,
     * otherwise falls back to returning the input as-is (for GrapesJS HTML that
     * is already inlined by the client-side editor).
     */
    public function render(string $mjml): string
    {
        $serverUrl = config('services.mjml.url');

        if (! $serverUrl) {
            return $mjml;
        }

        try {
            $response = Http::timeout(10)->post("{$serverUrl}/v1/render", ['mjml' => $mjml]);

            if ($response->successful()) {
                return $response->json('html', $mjml);
            }
        } catch (\Throwable $e) {
            Log::warning('MjmlRenderer: failed to render via server', ['error' => $e->getMessage()]);
        }

        return $mjml;
    }
}
