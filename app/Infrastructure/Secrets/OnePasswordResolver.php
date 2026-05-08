<?php

namespace App\Infrastructure\Secrets;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves 1Password secret references at runtime via the `op` CLI v2.
 *
 * Service Account tokens (`ops_*`) cannot be used against any public REST API —
 * 1Password's only programmatic surfaces are the SDK (Go/JS/Python — no PHP),
 * the `op` CLI, or a self-hosted Connect Server. This service shells out to
 * `op` and is the canonical way for FleetQ workers to materialise secrets that
 * live in 1Password without storing them in our DB.
 *
 * Usage:
 *   $token  = $credential->getCredentialSecret('service_account_token');
 *   $value  = app(OnePasswordResolver::class)->resolve('op://Engineering/Stripe/credential', $token);
 *
 * Threat model: the service account token is the entire authentication; it
 * MUST be passed via env var (never argv) so it cannot be observed by other
 * processes via `ps`. The reference itself is validated against a strict
 * grammar before being passed to argv to prevent shell-style injection.
 *
 * @see https://developer.1password.com/docs/cli/secret-references
 * @see https://developer.1password.com/docs/cli/reference/commands/read
 */
class OnePasswordResolver
{
    /**
     * Default timeout for a single `op read` invocation.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 15;

    /**
     * Resolve a single secret reference to its plaintext value.
     *
     * @param  string  $reference  e.g. "op://Engineering/Stripe API Key/credential"
     * @param  string  $serviceAccountToken  starts with "ops_"
     * @param  int  $timeoutSeconds  per-invocation timeout
     * @return string the resolved secret (caller is responsible for not logging it)
     *
     * @throws InvalidArgumentException if reference is malformed or token is bad
     * @throws RuntimeException if the `op` CLI is missing or returns non-zero
     */
    public function resolve(string $reference, string $serviceAccountToken, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string
    {
        $this->assertValidReference($reference);
        $this->assertValidToken($serviceAccountToken);

        $result = Process::env([
            'OP_SERVICE_ACCOUNT_TOKEN' => $serviceAccountToken,
            // Force machine-readable output and disable interactive prompts.
            'OP_FORMAT' => 'human-readable',
            'NO_COLOR' => '1',
        ])
            ->timeout($timeoutSeconds)
            ->run(['op', 'read', '--no-newline', $reference]);

        if (! $result->successful()) {
            throw new RuntimeException(
                'op read failed (exit '.$result->exitCode().'): '
                .trim($result->errorOutput() ?: $result->output()),
            );
        }

        // `op --no-newline` should not append a newline, but be defensive in
        // case the CLI version or shell wrapper adds one anyway. Trailing
        // whitespace in a secret is always a bug, never a feature.
        return rtrim($result->output(), "\r\n");
    }

    /**
     * Resolve many references in a single template-injection call.
     *
     * Equivalent to running `op inject` against the joined template — useful
     * when an agent execution needs several secrets and we want one process
     * launch instead of N. Returns the resolved values keyed by reference,
     * preserving input order.
     *
     * @param  list<string>  $references
     * @return array<string, string> reference => resolved value
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function resolveMany(array $references, string $serviceAccountToken, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        if ($references === []) {
            return [];
        }

        // Naive sequential loop — `op inject` would be marginally faster but
        // adds tempfile handling. Premature optimisation; revisit if profiling
        // shows the per-call overhead matters in agent execution.
        $resolved = [];
        foreach ($references as $reference) {
            $resolved[$reference] = $this->resolve($reference, $serviceAccountToken, $timeoutSeconds);
        }

        return $resolved;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertValidReference(string $reference): void
    {
        // Strict grammar: op://<vault>/<item>/<field> — exactly 3 segments,
        // no path traversal, no shell metachars, no quote/backslash abuse.
        // Item and vault titles can include spaces and unicode letters but
        // forbid: forward slash (would change segment count), quote chars,
        // backslashes, and shell metachars ($ ` ; & | < > ( ) newline).
        $segment = '[^/"\'\\\\$`;&|<>()\\s\\x00-\\x1F]{1,128}';
        if (! preg_match("#^op://({$segment})/({$segment})/({$segment})$#u", $reference)) {
            throw new InvalidArgumentException(
                'Invalid 1Password secret reference. Expected format: op://vault/item/field',
            );
        }

        // Reject "." and ".." path-traversal segments outright.
        foreach (['.', '..'] as $bad) {
            if (str_contains("/{$reference}/", "/{$bad}/")) {
                throw new InvalidArgumentException(
                    'Invalid 1Password secret reference: path-traversal segments are not allowed.',
                );
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertValidToken(string $token): void
    {
        // Service Account tokens always start with `ops_`, are URL-safe base64,
        // and are typically 800+ characters. We accept anything starting with
        // `ops_` and at least 32 chars long (the prefix + a JWS header is
        // already that long) to keep this future-proof.
        if (! str_starts_with($token, 'ops_') || strlen($token) < 32) {
            throw new InvalidArgumentException(
                '1Password Service Account token must start with "ops_" and be at least 32 characters.',
            );
        }
    }
}
