<?php

namespace App\Mcp\Protocol;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The opaque `requestState` blob of SEP-2322.
 *
 * The spec is blunt about the threat model: the client is an untrusted
 * intermediary, servers MUST always validate the state, SHOULD encrypt it for
 * confidentiality and integrity, and MUST cryptographically bind anything
 * user-specific to the original user and verify it against the *currently
 * authenticated* caller on the retry.
 *
 * So this is not a handle. A bare `approval_requests.id` would be trivially
 * swappable between tenants. It is an encrypted envelope that names a row:
 * authoritative state stays in the database (where the inbox, the audit trail
 * and ExpireStaleApprovals already operate on it), while everything needed to
 * *verify the resumption* travels with the client. Verification touches only the
 * envelope and the row it names — no session affinity, so any node behind the
 * load balancer can serve the retry (SEP-2567).
 *
 * `tool` + `argsHash` pin an envelope to the exact call it was minted for: a
 * state issued for `git_pr_merge` on PR #7 cannot resume PR #9.
 */
final class RequestState
{
    /** Envelope format version, so a future shape change can be detected rather than mis-parsed. */
    public const VERSION = 1;

    /**
     * Envelope lifetime. Deliberately NOT the approval's own `expires_at`:
     * this one asks "is this resumption attempt still fresh", the other asks
     * "is the human decision still valid". A stale envelope means start over;
     * an expired approval is a governance outcome.
     */
    public const TTL_SECONDS = 86400;

    public function __construct(
        public readonly string $approvalRequestId,
        public readonly string $teamId,
        public readonly ?string $userId,
        public readonly string $tool,
        public readonly string $argsHash,
        public readonly int $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function issue(
        string $approvalRequestId,
        string $teamId,
        ?string $userId,
        string $tool,
        array $arguments,
    ): self {
        return new self(
            approvalRequestId: $approvalRequestId,
            teamId: $teamId,
            userId: $userId,
            tool: $tool,
            argsHash: self::hashArguments($arguments),
            expiresAt: now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        );
    }

    public function encode(): string
    {
        return Crypt::encryptString(json_encode([
            'v' => self::VERSION,
            'a' => $this->approvalRequestId,
            't' => $this->teamId,
            'u' => $this->userId,
            'n' => $this->tool,
            'h' => $this->argsHash,
            'e' => $this->expiresAt,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Decode a client-supplied blob. Returns null for anything that is not a
     * well-formed, unexpired envelope this server minted.
     *
     * Every failure collapses to null on purpose: the caller must not be able
     * to tell a forged blob from an expired one from a wrong-version one, and
     * the only safe response to all three is "no resumable state".
     */
    public static function decode(?string $blob): ?self
    {
        if ($blob === null || $blob === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($blob), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['v'] ?? null) !== self::VERSION) {
            return null;
        }

        foreach (['a', 't', 'n', 'h', 'e'] as $required) {
            if (! isset($payload[$required])) {
                return null;
            }
        }

        $state = new self(
            approvalRequestId: (string) $payload['a'],
            teamId: (string) $payload['t'],
            userId: isset($payload['u']) ? (string) $payload['u'] : null,
            tool: (string) $payload['n'],
            argsHash: (string) $payload['h'],
            expiresAt: (int) $payload['e'],
        );

        return $state->isExpired() ? null : $state;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= now()->getTimestamp();
    }

    /**
     * The SEP-2322 binding check. An envelope only resumes the call it was
     * minted for, for the tenant and user it was minted for.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function matches(string $tool, array $arguments, string $teamId, ?string $userId): bool
    {
        if (! hash_equals($this->tool, $tool) || ! hash_equals($this->teamId, $teamId)) {
            return false;
        }

        if (! hash_equals($this->argsHash, self::hashArguments($arguments))) {
            return false;
        }

        // A state minted for an identified user may only be resumed by that
        // user. A state minted without one (stdio, machine token) stays
        // team-bound only — there is no user identity to bind it to.
        if ($this->userId !== null) {
            return $userId !== null && hash_equals($this->userId, $userId);
        }

        return true;
    }

    /**
     * Order-insensitive so a client that re-serialises its arguments map with a
     * different key order still resumes; value-sensitive so changing any
     * argument does not.
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function hashArguments(array $arguments): string
    {
        $normalised = self::normalise($arguments);

        return hash('sha256', json_encode($normalised, JSON_THROW_ON_ERROR));
    }

    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = array_map(static fn ($v) => self::normalise($v), $value);

        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
