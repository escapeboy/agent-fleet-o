<?php

namespace App\Domain\Email\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MjmlRenderer
{
    /**
     * Render MJML markup to inlined HTML.
     *
     * Priority: MJML HTTP server → npx mjml CLI → raw input passthrough.
     */
    public function render(string $mjml): string
    {
        if (! str_contains($mjml, '<mjml')) {
            return $mjml;
        }

        $html = $this->renderViaServer($mjml) ?? $this->renderViaCli($mjml);

        return $html ?? $mjml;
    }

    private function renderViaServer(string $mjml): ?string
    {
        $serverUrl = config('services.mjml.url');

        if (! $serverUrl) {
            return null;
        }

        try {
            $response = Http::timeout(10)->post("{$serverUrl}/v1/render", ['mjml' => $mjml]);

            if ($response->successful()) {
                return $response->json('html');
            }
        } catch (\Throwable $e) {
            Log::warning('MjmlRenderer: HTTP server failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function renderViaCli(string $mjml): ?string
    {
        if (! function_exists('proc_open')) {
            Log::info('MjmlRenderer: proc_open disabled, CLI unavailable');

            return null;
        }

        try {
            $result = Process::timeout(30)
                ->input($mjml)
                ->run('npx --yes mjml -i -s');

            if ($result->successful() && str_contains($result->output(), '<html')) {
                return $result->output();
            }

            Log::warning('MjmlRenderer: CLI failed', ['exitCode' => $result->exitCode(), 'stderr' => $result->errorOutput()]);
        } catch (\Throwable $e) {
            Log::warning('MjmlRenderer: CLI unavailable', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
