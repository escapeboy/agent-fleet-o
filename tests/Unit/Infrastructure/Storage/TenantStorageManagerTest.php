<?php

namespace Tests\Unit\Infrastructure\Storage;

use App\Infrastructure\Storage\TenantStorageManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TenantStorageManagerTest extends TestCase
{
    private string $teamId = '0190a000-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenant_storage.private_disk' => 's3_private',
            'tenant_storage.public_disk' => 's3_public',
            'tenant_storage.prefix' => 'tenants',
        ]);

        Storage::fake('s3_private');
        Storage::fake('s3_public');
    }

    #[Test]
    public function put_stores_private_file_under_team_prefix(): void
    {
        $key = $this->manager()->put(
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'chatbot-kb',
            TenantStorageManager::VISIBILITY_PRIVATE,
            $this->teamId,
        );

        $this->assertStringStartsWith("tenants/{$this->teamId}/chatbot-kb/", $key);
        $this->assertStringEndsWith('.pdf', $key);
        Storage::disk('s3_private')->assertExists($key);
    }

    #[Test]
    public function put_public_writes_to_public_disk_only(): void
    {
        $key = $this->manager()->put(
            UploadedFile::fake()->image('logo.png'),
            'website-assets',
            TenantStorageManager::VISIBILITY_PUBLIC,
            $this->teamId,
        );

        Storage::disk('s3_public')->assertExists($key);
        $this->assertEmpty(Storage::disk('s3_private')->allFiles());
    }

    #[Test]
    public function private_put_never_touches_public_disk(): void
    {
        $this->manager()->put(
            UploadedFile::fake()->create('secret.txt', 1),
            'knowledge',
            TenantStorageManager::VISIBILITY_PRIVATE,
            $this->teamId,
        );

        $this->assertEmpty(Storage::disk('s3_public')->allFiles());
    }

    #[Test]
    public function resolve_team_id_throws_without_context(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->put(
            UploadedFile::fake()->create('x.txt', 1),
            'knowledge',
        );
    }

    #[Test]
    public function resolve_team_id_falls_back_to_ai_binding(): void
    {
        $this->app->instance('ai.current_team_id', $this->teamId);

        $key = $this->manager()->put(
            UploadedFile::fake()->create('x.txt', 1),
            'knowledge',
        );

        $this->assertStringStartsWith("tenants/{$this->teamId}/knowledge/", $key);
    }

    #[Test]
    public function team_id_from_key_extracts_segment(): void
    {
        $manager = $this->manager();

        $this->assertSame(
            $this->teamId,
            $manager->teamIdFromKey("tenants/{$this->teamId}/media/abc.png"),
        );
        $this->assertNull($manager->teamIdFromKey('other/path/file.png'));
    }

    #[Test]
    public function unknown_visibility_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->disk('bogus');
    }

    private function manager(): TenantStorageManager
    {
        return new TenantStorageManager;
    }
}
