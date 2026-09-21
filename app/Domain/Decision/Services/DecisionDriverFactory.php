<?php

namespace App\Domain\Decision\Services;

use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\Drivers\ClaudeCliDriver;
use App\Domain\Decision\Drivers\LlmStructuredDriver;
use App\Domain\Decision\Drivers\SystemOneDriver;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

final class DecisionDriverFactory
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function make(string $name, ?string $teamId = null): DecisionModel
    {
        $config = config("decision.drivers.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown decision driver [{$name}].");
        }

        return match ($config['type'] ?? null) {
            'system_one' => $this->systemOne($name, $config),
            'llm' => new LlmStructuredDriver(
                gateway: $this->gateway,
                provider: (string) $config['provider'],
                model: (string) $config['model'],
                maxTokens: (int) ($config['max_tokens'] ?? 2048),
                temperature: (float) ($config['temperature'] ?? 0.0),
                teamId: $teamId ?? (is_string(config('decision.team_id')) ? config('decision.team_id') : null),
            ),
            'cli' => new ClaudeCliDriver(
                binary: (string) ($config['binary'] ?? 'claude'),
                model: (string) $config['model'],
                timeoutSeconds: (int) ($config['timeout'] ?? 180),
                concurrency: (int) ($config['concurrency'] ?? 4),
            ),
            default => throw new InvalidArgumentException("Decision driver [{$name}] has no usable type."),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function systemOne(string $name, array $config): SystemOneDriver
    {
        $baseUrl = (string) ($config['base_url'] ?? '');
        $key = (string) ($config['key'] ?? '');

        if ($baseUrl === '') {
            throw new InvalidArgumentException("Decision driver [{$name}] has no base_url configured.");
        }

        if ($key === '') {
            throw new InvalidArgumentException(
                "Decision driver [{$name}] has no API key. Run the command under `op run --env-file=.env.op`.",
            );
        }

        return new SystemOneDriver(
            http: $this->http,
            baseUrl: $baseUrl,
            apiKey: $key,
            model: (string) $config['model'],
            timeout: (float) ($config['timeout'] ?? 5.0),
            retries: (int) ($config['retries'] ?? 3),
        );
    }
}
