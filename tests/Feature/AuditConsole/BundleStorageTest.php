<?php

namespace Tests\Feature\AuditConsole;

use FleetQ\BorunaAudit\Services\BundleStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BundleStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_path_traversal_is_rejected(): void
    {
        $storage = $this->app->make(BundleStorage::class);

        $this->expectException(\RuntimeException::class);
        $storage->bundlePath('../../../etc', 'run-1');
    }

    public function test_tenant_isolation_is_maintained(): void
    {
        $storageService = $this->app->make(BundleStorage::class);

        $pathA = $storageService->bundlePath('tenant-a', 'run-abc');
        $pathB = $storageService->bundlePath('tenant-b', 'run-abc');

        $this->assertStringContainsString('tenant-a', $pathA);
        $this->assertStringContainsString('tenant-b', $pathB);
        $this->assertNotEquals($pathA, $pathB);
        $this->assertStringNotContainsString('tenant-b', $pathA);
    }

    public function test_bundle_files_are_written_and_readable(): void
    {
        Storage::fake('boruna_bundles');

        $storage = $this->app->make(BundleStorage::class);
        $evidence = ['hash_chain' => [['event' => 'start', 'hash' => 'abc', 'prev_hash' => null]]];

        $path = $storage->writeBundleFiles('tenant-x', 'run-123', $evidence);

        $this->assertNotEmpty($path);

        Storage::disk('boruna_bundles')->assertExists($path.'/evidence.json');
    }
}
