<?php

namespace App\Domain\Tool\Services;

use App\Domain\Credential\Models\Credential;
use App\Domain\Tool\DTOs\SshExecutionResult;
use App\Domain\Tool\Exceptions\SshHostNotAllowedException;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SshExecutor
{
    private const DEFAULT_TIMEOUT = 30;

    private const MAX_OUTPUT_BYTES = 1_048_576; // 1 MB

    public function __construct(
        private readonly SshHostFingerprintStore $fingerprintStore,
        private readonly ?SshHostPolicy $hostPolicy = null,
    ) {}

    /**
     * Execute a command on a remote server via SSH.
     *
     * @throws SshHostNotAllowedException
     * @throws RuntimeException
     */
    public function execute(
        string $teamId,
        string $host,
        int $port,
        string $username,
        string $credentialId,
        string $command,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): SshExecutionResult {
        // Policy check (cloud blocks RFC1918, community no-ops)
        $this->hostPolicy?->validateHost($host);

        // Resolve SSH key from credential vault
        $credential = Credential::findOrFail($credentialId);
        $secretData = $credential->secret_data;
        $privateKeyPem = $secretData['private_key'] ?? null;
        $passphrase = $secretData['passphrase'] ?? null;

        if (! $privateKeyPem) {
            throw new RuntimeException('SSH credential is missing private_key in secret_data.');
        }

        $startMs = (int) round(microtime(true) * 1000);

        $ssh = new SSH2($host, $port, $timeout);

        // Load the private key (Ed25519, RSA, ECDSA — phpseclib handles all)
        $key = $passphrase
            ? PublicKeyLoader::load($privateKeyPem, $passphrase)
            : PublicKeyLoader::load($privateKeyPem);

        // TOFU fingerprint verification
        $fingerprint = $ssh->getServerPublicHostKey();
        if ($fingerprint) {
            $sha256 = base64_encode(hash('sha256', $fingerprint, true));
            $this->fingerprintStore->verify($teamId, $host, $port, $sha256);
        }

        if (! $ssh->login($username, $key)) {
            throw new RuntimeException("SSH authentication failed for {$username}@{$host}:{$port}");
        }

        $credential->touchLastUsed();

        $output = $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();

        $durationMs = (int) round(microtime(true) * 1000) - $startMs;

        if (mb_strlen($output) > self::MAX_OUTPUT_BYTES) {
            $output = mb_substr($output, 0, self::MAX_OUTPUT_BYTES)
                ."\n... [output truncated at ".self::MAX_OUTPUT_BYTES.' bytes]';
        }

        Log::info('SshExecutor: command executed', [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
        ]);

        return new SshExecutionResult(
            output: $output,
            exitCode: $exitCode ?? 0,
            durationMs: $durationMs,
        );
    }
}
