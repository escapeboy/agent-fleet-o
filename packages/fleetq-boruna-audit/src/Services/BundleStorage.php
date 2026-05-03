<?php

namespace FleetQ\BorunaAudit\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BundleStorage
{
    private function disk(): Filesystem
    {
        return Storage::disk(config('boruna_audit.storage_disk', 'boruna_bundles'));
    }

    private function bundleRoot(): string
    {
        return storage_path('app/boruna_bundles');
    }

    public function writeBundleFiles(string $tenantId, string $runId, array $evidenceData): string
    {
        $relativePath = $this->relativePath($tenantId, $runId);

        $this->assertSafePath($relativePath);

        $this->disk()->makeDirectory($relativePath);
        $this->disk()->put("{$relativePath}/evidence.json", json_encode($evidenceData, JSON_PRETTY_PRINT));

        if (isset($evidenceData['audit_log'])) {
            $this->disk()->put("{$relativePath}/audit_log.json", json_encode($evidenceData['audit_log'], JSON_PRETTY_PRINT));
        }

        if (isset($evidenceData['hash_chain'])) {
            $this->disk()->put("{$relativePath}/hash_chain.json", json_encode($evidenceData['hash_chain'], JSON_PRETTY_PRINT));
        }

        return $relativePath;
    }

    public function bundlePath(string $tenantId, string $runId): string
    {
        $relativePath = $this->relativePath($tenantId, $runId);
        $this->assertSafePath($relativePath);

        return $relativePath;
    }

    public function bundleExists(string $tenantId, string $runId): bool
    {
        return $this->disk()->exists($this->relativePath($tenantId, $runId).'/evidence.json');
    }

    public function readEvidenceFile(string $bundlePath): ?array
    {
        $this->assertSafePath($bundlePath);

        $content = $this->disk()->get("{$bundlePath}/evidence.json");
        if ($content === null) {
            return null;
        }

        return json_decode($content, true);
    }

    public function bundleAbsolutePath(string $bundlePath): string
    {
        $this->assertSafePath($bundlePath);

        return $this->bundleRoot().DIRECTORY_SEPARATOR.ltrim($bundlePath, '/');
    }

    private function relativePath(string $tenantId, string $runId): string
    {
        $now = now();

        return sprintf('%s/%s/%s/%s', $tenantId, $now->format('Y'), $now->format('m'), $runId);
    }

    private function assertSafePath(string $relativePath): void
    {
        // Guard 1: literal traversal segments and null bytes.
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            throw new RuntimeException('Path traversal attempt detected in Boruna bundle path.');
        }

        // Guard 2: prefix check against the configured root. We construct the
        // absolute path via string concat (not realpath, which fails on non-existent
        // paths) and verify it is still inside the bundle root. This covers the case
        // where the storage directory does not exist yet on a fresh install.
        $root = rtrim($this->bundleRoot(), DIRECTORY_SEPARATOR);
        $normalized = $root.DIRECTORY_SEPARATOR.ltrim(
            str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
            DIRECTORY_SEPARATOR,
        );

        if (! str_starts_with($normalized, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Boruna bundle path escapes storage root.');
        }
    }
}
