<?php

namespace Tests\Feature\Docs;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers where GET /docs/api.json reads the spec from and where the weekly
 * export writes it.
 *
 * The route tests point the application's storage and public paths at a
 * temporary directory, so a real public/api.json is never overwritten or removed.
 */
class OpenApiSpecRouteTest extends TestCase
{
    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if ($this->sandbox !== null) {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }

    public function test_serves_spec_from_storage_when_present(): void
    {
        $this->useSandboxPaths();
        $this->writeSpec(storage_path('app/openapi/api.json'), 'stored-sentinel');

        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertSame('stored-sentinel', $response->json('info.title'));
    }

    public function test_storage_file_takes_precedence_over_public_file(): void
    {
        $this->useSandboxPaths();
        $this->writeSpec(storage_path('app/openapi/api.json'), 'stored-sentinel');
        $this->writeSpec(public_path('api.json'), 'public-sentinel');

        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $this->assertSame('stored-sentinel', $response->json('info.title'));
    }

    public function test_falls_back_to_public_file_when_storage_missing(): void
    {
        $this->useSandboxPaths();
        $this->writeSpec(public_path('api.json'), 'public-sentinel');

        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertSame('public-sentinel', $response->json('info.title'));
    }

    public function test_generates_live_when_no_file_exists(): void
    {
        $this->useSandboxPaths();

        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertArrayHasKey('openapi', $response->json());

        $paths = array_keys($response->json('paths'));
        $this->assertNotEmpty($paths);

        // Scramble reports paths relative to the "api" base path.
        foreach ($paths as $path) {
            $this->assertStringStartsWith('/v1/', $path);
        }
    }

    public function test_scheduler_exports_spec_to_storage_path(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'scramble:export'),
        ));

        $this->assertCount(1, $events);
        $this->assertStringContainsString(storage_path('app/openapi/api.json'), $events[0]->command);
        $this->assertStringNotContainsString('public/api.json', $events[0]->command);
    }

    private function useSandboxPaths(): void
    {
        $this->sandbox = sys_get_temp_dir().'/openapi-spec-route-'.bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->sandbox.'/public');
        File::ensureDirectoryExists($this->sandbox.'/storage');

        $this->app->usePublicPath($this->sandbox.'/public');
        $this->app->useStoragePath($this->sandbox.'/storage');
    }

    private function writeSpec(string $path, string $title): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['openapi' => '3.1.0', 'info' => ['title' => $title]]));
    }
}
