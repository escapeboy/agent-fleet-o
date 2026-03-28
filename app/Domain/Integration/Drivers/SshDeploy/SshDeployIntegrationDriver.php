<?php

namespace App\Domain\Integration\Drivers\SshDeploy;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;

class SshDeployIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'ssh_deploy';
    }

    public function label(): string
    {
        return 'SSH Deploy';
    }

    public function description(): string
    {
        return 'Deploy applications via SSH by running pre-configured scripts on remote servers.';
    }

    public function authType(): AuthType
    {
        return AuthType::BearerToken;
    }

    public function credentialSchema(): array
    {
        return [
            'host' => ['type' => 'string', 'required' => true, 'label' => 'SSH Host'],
            'port' => ['type' => 'integer', 'required' => false, 'label' => 'SSH Port (default: 22)'],
            'username' => ['type' => 'string', 'required' => true, 'label' => 'SSH Username'],
            'private_key' => ['type' => 'string', 'required' => false, 'label' => 'SSH Private Key (PEM)'],
            'public_key' => ['type' => 'string', 'required' => false, 'label' => 'SSH Public Key (OpenSSH format, required when using private_key)'],
            'password' => ['type' => 'string', 'required' => false, 'label' => 'SSH Password'],
            'deploy_path' => ['type' => 'string', 'required' => false, 'label' => 'Deploy Path (default: /var/www/app)'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $host = $credentials['host'] ?? null;
        $username = $credentials['username'] ?? null;

        if (! $host || ! $username) {
            return false;
        }

        return ! empty($credentials['private_key']) || ! empty($credentials['password']);
    }

    public function ping(Integration $integration): HealthResult
    {
        $host = $integration->getCredentialSecret('host');
        $username = $integration->getCredentialSecret('username');

        if (! $host || ! $username) {
            return HealthResult::fail('SSH host and username are required.');
        }

        $start = microtime(true);

        try {
            $connection = $this->connect($integration);
            $latency = (int) ((microtime(true) - $start) * 1000);
            @ssh2_disconnect($connection);

            return HealthResult::ok($latency);
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('deploy_started', 'Deploy Started', 'A deploy script has been started.'),
            new TriggerDefinition('deploy_succeeded', 'Deploy Succeeded', 'A deploy script completed successfully.'),
            new TriggerDefinition('deploy_failed', 'Deploy Failed', 'A deploy script failed.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('run_deploy', 'Run Deploy Script', 'Run the configured deploy script on the remote server.', [
                'script' => ['type' => 'string', 'required' => false, 'description' => 'Deploy script filename in deploy_path (default: deploy.sh)'],
                'environment' => ['type' => 'string', 'required' => false, 'description' => 'Target environment (e.g. production, staging)'],
            ]),
            new ActionDefinition('check_health', 'Check Health', 'Run the health check script on the remote server.', [
                'script' => ['type' => 'string', 'required' => false, 'description' => 'Health check script filename in deploy_path (default: health.sh)'],
            ]),
            new ActionDefinition('rollback', 'Rollback', 'Run the rollback script on the remote server.', [
                'script' => ['type' => 'string', 'required' => false, 'description' => 'Rollback script filename in deploy_path (default: rollback.sh)'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0;
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        return match ($action) {
            'run_deploy' => $this->runScript($integration, $params['script'] ?? 'deploy.sh', $params['environment'] ?? 'production'),
            'check_health' => $this->runScript($integration, $params['script'] ?? 'health.sh', null),
            'rollback' => $this->runScript($integration, $params['script'] ?? 'rollback.sh', null),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function connect(Integration $integration): mixed
    {
        if (! function_exists('ssh2_connect')) {
            throw new \RuntimeException('The ssh2 PHP extension is required. Install with: pecl install ssh2');
        }

        $host = (string) $integration->getCredentialSecret('host');
        $port = (int) ($integration->getCredentialSecret('port') ?: 22);
        $username = (string) $integration->getCredentialSecret('username');
        $privateKey = $integration->getCredentialSecret('private_key');
        $password = $integration->getCredentialSecret('password');

        $connection = ssh2_connect($host, $port);

        if (! $connection) {
            throw new \RuntimeException("Failed to connect to {$host}:{$port}");
        }

        if ($privateKey) {
            $publicKey = $integration->getCredentialSecret('public_key');

            if (! $publicKey) {
                throw new \RuntimeException('public_key is required when using private_key authentication.');
            }

            $keyFile = tempnam(sys_get_temp_dir(), 'sshk_');
            $pubFile = $keyFile.'.pub';

            try {
                file_put_contents($keyFile, (string) $privateKey);
                file_put_contents($pubFile, (string) $publicKey);
                chmod($keyFile, 0600);
                chmod($pubFile, 0644);

                $authenticated = ssh2_auth_pubkey_file($connection, $username, $pubFile, $keyFile);
            } finally {
                @unlink($keyFile);
                @unlink($pubFile);
            }

            if (! $authenticated) {
                throw new \RuntimeException('SSH public key authentication failed.');
            }
        } elseif ($password) {
            if (! ssh2_auth_password($connection, $username, (string) $password)) {
                throw new \RuntimeException('SSH password authentication failed.');
            }
        } else {
            throw new \RuntimeException('No SSH auth method configured (private_key or password required).');
        }

        return $connection;
    }

    private function runScript(Integration $integration, string $scriptName, ?string $environment): array
    {
        // Restrict script name to basename only (no path traversal)
        $scriptName = basename($scriptName);
        $deployPath = rtrim((string) ($integration->getCredentialSecret('deploy_path') ?: '/var/www/app'), '/');

        $connection = $this->connect($integration);

        try {
            // Escape all values interpolated into the remote shell command
            $envPrefix = $environment ? 'ENVIRONMENT='.escapeshellarg($environment).' ' : '';
            $cmd = 'cd '.escapeshellarg($deployPath).' && '.$envPrefix.'bash '.escapeshellarg($scriptName).' 2>&1';

            $stream = ssh2_exec($connection, $cmd);

            if ($stream === false) {
                throw new \RuntimeException("Failed to run script {$scriptName} in {$deployPath}");
            }

            stream_set_blocking($stream, true);
            $output = (string) stream_get_contents($stream);
            fclose($stream);
        } finally {
            // Always close the connection to avoid resource leaks in Horizon workers
            @ssh2_disconnect($connection);
        }

        $output = trim($output);

        // ssh2_exec() does not expose exit codes; use a conservative heuristic
        // (false-positive on scripts that print "error" in info messages is acceptable)
        $success = ! str_contains(strtolower($output), 'error') && ! str_contains(strtolower($output), 'failed');

        return [
            'success' => $success,
            'output' => $output,
            'script' => "{$deployPath}/{$scriptName}",
            'environment' => $environment,
        ];
    }
}
