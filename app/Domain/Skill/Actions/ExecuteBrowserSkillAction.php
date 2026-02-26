<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Executes a Browser Automation skill via the Browserless REST API.
 *
 * Supported actions:
 *   - screenshot: Capture a full-page or viewport screenshot (PNG)
 *   - scrape:     Fetch rendered HTML and extracted text from a URL
 *   - pdf:        Generate a PDF of a page
 *
 * Requires the optional browserless Docker service:
 *   docker compose --profile browser up -d
 *
 * Feature-gated behind BROWSER_SKILL_ENABLED=true env var.
 * Zero credits — no LLM calls are made.
 */
class ExecuteBrowserSkillAction
{
    /**
     * @return array{execution: SkillExecution, output: array|null}
     */
    public function execute(
        Skill $skill,
        array $input,
        string $teamId,
        string $userId,
        ?string $agentId = null,
        ?string $experimentId = null,
    ): array {
        if (! config('browser.enabled', false)) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'Browser Skill is not enabled. Set BROWSER_SKILL_ENABLED=true and start the browserless service.',
            );
        }

        $config = is_array($skill->configuration) ? $skill->configuration : [];
        $action = $input['action'] ?? $config['action'] ?? 'scrape';
        $url = $input['url'] ?? $config['url'] ?? null;

        if (! $url) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'Missing required input: url.',
            );
        }

        if (! $this->isSafeUrl($url)) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'URL is not allowed: must use http/https scheme and must not target internal network addresses.',
            );
        }

        $startTime = hrtime(true);

        try {
            $output = match ($action) {
                'screenshot' => $this->takeScreenshot($url, $config),
                'scrape' => $this->scrapeContent($url, $config),
                'pdf' => $this->generatePdf($url, $config),
                default => throw new \InvalidArgumentException(
                    "Unsupported browser action: {$action}. Supported: screenshot, scrape, pdf.",
                ),
            };

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $output,
                'duration_ms' => $durationMs,
                'cost_credits' => 0,
            ]);

            $skill->recordExecution(true, $durationMs);

            return [
                'execution' => $execution,
                'output' => $output,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $skill->recordExecution(false, $durationMs);

            Log::warning('ExecuteBrowserSkillAction: request failed', [
                'action' => $action,
                'url' => $url ?? null,
                'error' => $e->getMessage(),
            ]);

            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                $e->getMessage(), $durationMs,
            );
        }
    }

    private function takeScreenshot(string $url, array $config): array
    {
        $response = $this->request('/screenshot', [
            'url' => $url,
            'options' => [
                'type' => $config['screenshot_type'] ?? 'png',
                'fullPage' => $config['full_page'] ?? false,
            ],
            'waitFor' => $config['wait_for'] ?? 2000,
            'viewport' => [
                'width' => $config['viewport_width'] ?? 1280,
                'height' => $config['viewport_height'] ?? 720,
            ],
        ]);

        $body = $response->body();

        return [
            'action' => 'screenshot',
            'url' => $url,
            'content_type' => 'image/png',
            'data' => base64_encode($body),
            'size_bytes' => strlen($body),
        ];
    }

    private function scrapeContent(string $url, array $config): array
    {
        $response = $this->request('/content', [
            'url' => $url,
            'waitFor' => $config['wait_for'] ?? 2000,
        ]);

        $html = $response->body();
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

        return [
            'action' => 'scrape',
            'url' => $url,
            'content' => mb_substr($html, 0, 100_000),
            'text' => mb_substr($text, 0, 50_000),
            'content_length' => strlen($html),
        ];
    }

    private function generatePdf(string $url, array $config): array
    {
        $response = $this->request('/pdf', [
            'url' => $url,
            'options' => [
                'printBackground' => true,
                'format' => $config['pdf_format'] ?? 'A4',
            ],
            'waitFor' => $config['wait_for'] ?? 2000,
        ]);

        $body = $response->body();

        return [
            'action' => 'pdf',
            'url' => $url,
            'content_type' => 'application/pdf',
            'data' => base64_encode($body),
            'size_bytes' => strlen($body),
        ];
    }

    private function request(string $path, array $payload): Response
    {
        $baseUrl = rtrim((string) config('browser.url', 'http://browserless:3000'), '/');
        $token = config('browser.token');
        $timeout = (int) config('browser.timeout', 30);

        $http = Http::timeout($timeout)->acceptJson();

        if ($token) {
            $http = $http->withHeaders(['Authorization' => "Token {$token}"]);
        }

        $response = $http->post("{$baseUrl}{$path}", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Browserless {$path} failed [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response;
    }

    /**
     * PHP-layer SSRF guard: validates URL scheme and IP ranges at request time.
     *
     * LIMITATION: This check resolves DNS at the PHP layer. Browserless performs
     * its own DNS resolution when executing the browser task. For full DNS rebinding
     * protection in production, configure Docker network isolation:
     * - Run Browserless on an isolated Docker network with no access to RFC-1918 ranges
     * - Use the `agent-fleet-internal` network (internal: true) for postgres/redis
     * See docker-compose.yml for the recommended network topology.
     */
    private function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (! $parsed || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if (! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Block loopback
        if ($host === 'localhost' || $host === '::1') {
            return false;
        }

        // Resolve hostname to IP for range checks
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Block private, loopback, link-local, and reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    /**
     * @return array{execution: SkillExecution, output: null}
     */
    private function failExecution(
        Skill $skill,
        string $teamId,
        ?string $agentId,
        ?string $experimentId,
        array $input,
        string $errorMessage,
        int $durationMs = 0,
    ): array {
        $execution = SkillExecution::create([
            'skill_id' => $skill->id,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'failed',
            'input' => $input,
            'output' => null,
            'duration_ms' => $durationMs,
            'cost_credits' => 0,
            'error_message' => $errorMessage,
        ]);

        return [
            'execution' => $execution,
            'output' => null,
        ];
    }
}
