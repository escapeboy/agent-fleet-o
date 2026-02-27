<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Exceptions\SshFingerprintMismatchException;
use App\Domain\Tool\Models\SshHostFingerprint;
use Illuminate\Support\Facades\Log;

class SshHostFingerprintStore
{
    /**
     * Verify a host fingerprint using Trust On First Use (TOFU).
     *
     * On the first connection to a host:port, the fingerprint is stored and trusted.
     * On subsequent connections, the stored fingerprint is compared — a mismatch
     * raises SshFingerprintMismatchException to prevent MITM attacks.
     *
     * @throws SshFingerprintMismatchException
     */
    public function verify(string $teamId, string $host, int $port, string $fingerprintSha256): void
    {
        $existing = SshHostFingerprint::where('team_id', $teamId)
            ->where('host', $host)
            ->where('port', $port)
            ->first();

        if ($existing === null) {
            // TOFU: first connection — store and trust the fingerprint
            SshHostFingerprint::create([
                'team_id' => $teamId,
                'host' => $host,
                'port' => $port,
                'fingerprint_sha256' => $fingerprintSha256,
                'verified_at' => now(),
            ]);

            Log::info('SshHostFingerprintStore: new host fingerprint stored (TOFU)', [
                'host' => $host,
                'port' => $port,
                'fingerprint' => $fingerprintSha256,
            ]);

            return;
        }

        if (! hash_equals($existing->fingerprint_sha256, $fingerprintSha256)) {
            Log::warning('SshHostFingerprintStore: fingerprint mismatch — possible MITM', [
                'host' => $host,
                'port' => $port,
                'stored' => $existing->fingerprint_sha256,
                'received' => $fingerprintSha256,
            ]);

            throw new SshFingerprintMismatchException($host, $port);
        }

        // Fingerprint matches — update verified_at timestamp
        $existing->update(['verified_at' => now()]);
    }
}
