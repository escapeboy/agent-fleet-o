<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Executes an HTTP Request node — an inline outbound HTTP call.
 *
 * Config shape:
 * {
 *   "method": "POST",
 *   "url": "https://api.example.com/data",
 *   "headers": {"Authorization": "Bearer {{credential.my_key.value}}"},
 *   "body": "{{previous_node_id.text}}",
 *   "timeout": 30,
 *   "follow_redirects": true
 * }
 *
 * Security: URLs are validated via SsrfGuard. Credential references
 * ({{credential.name.field}}) resolve secret_data fields from team credentials.
 */
class HttpRequestNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $context = $this->buildStepContext($step, $experiment);

        // Inject team credentials into context so templates can reference them
        $context['credential'] = $this->resolveTeamCredentials($experiment->team_id);

        $method = strtoupper($config['method'] ?? 'GET');
        $url = $this->interpolate($config['url'] ?? '', $context);

        if (empty($url)) {
            throw new \InvalidArgumentException('HTTP Request node: url is required');
        }

        // SSRF guard — blocks requests to private/loopback addresses
        $this->ssrfGuard->validate($url);

        // Interpolate headers
        $headers = [];
        foreach ($config['headers'] ?? [] as $key => $value) {
            $headers[$key] = $this->interpolate((string) $value, $context);
        }

        // Interpolate body
        $body = '';
        if (! empty($config['body'])) {
            $body = $this->interpolate($config['body'], $context);
        }

        $timeout = min((int) ($config['timeout'] ?? 30), 120);
        $followRedirects = (bool) ($config['follow_redirects'] ?? true);

        try {
            $http = Http::withHeaders($headers)
                ->timeout($timeout)
                ->when(! $followRedirects, fn ($h) => $h->withoutRedirecting());

            $response = match ($method) {
                'GET' => $http->get($url),
                'POST' => $http->withBody($body, $this->detectContentType($headers))->post($url),
                'PUT' => $http->withBody($body, $this->detectContentType($headers))->put($url),
                'PATCH' => $http->withBody($body, $this->detectContentType($headers))->patch($url),
                'DELETE' => $http->delete($url),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            return [
                'response_body' => $response->body(),
                'status_code' => $response->status(),
                'ok' => $response->ok(),
            ];
        } catch (RequestException $e) {
            return [
                'response_body' => $e->response?->body() ?? $e->getMessage(),
                'status_code' => $e->response?->status() ?? 0,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build a keyed map of credential name → secret_data for template resolution.
     *
     * @return array<string, array<string, string>>
     */
    private function resolveTeamCredentials(string $teamId): array
    {
        $credentials = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', CredentialStatus::Active)
            ->get();

        $map = [];
        foreach ($credentials as $credential) {
            $map[$credential->name] = $credential->secret_data ?? [];
        }

        return $map;
    }

    private function detectContentType(array $headers): string
    {
        return $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/json';
    }
}
