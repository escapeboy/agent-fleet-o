<?php

namespace Tests\Feature\Docs;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    /**
     * Scramble keeps its "ignore default routes" flag in a static property, so
     * only a fresh process shows what happens on the first boot.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_application_route_serves_stored_spec_on_first_boot_of_a_process(): void
    {
        $this->useSandboxPaths();
        $this->writeSpec(storage_path('app/openapi/api.json'), 'stored-sentinel');

        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $this->assertSame('stored-sentinel', $response->json('info.title'));
    }

    public function test_scheduler_exports_spec_to_storage_path(): void
    {
        $event = $this->scheduledExportEvent();

        $this->assertStringContainsString(storage_path('app/openapi/api.json'), $event->command);
        $this->assertStringNotContainsString('public/api.json', $event->command);
    }

    /**
     * Runs the real scheduled event's before() hook and artisan command. Only the
     * storage root differs, so nothing is written to the real storage directory.
     */
    public function test_scheduled_export_writes_a_servable_spec_when_directory_is_missing(): void
    {
        $event = $this->scheduledExportEvent();
        $scheduledPath = storage_path('app/openapi/api.json');

        $this->useSandboxPaths();
        $target = storage_path('app/openapi/api.json');
        $this->assertDirectoryDoesNotExist(dirname($target));

        $event->callBeforeCallbacks($this->app);
        $this->assertDirectoryExists(dirname($target));

        $arguments = Str::after($event->command, 'scramble:export');
        $exitCode = Artisan::call('scramble:export'.str_replace($scheduledPath, $target, $arguments));

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFileExists($target);
        $this->assertFileDoesNotExist(public_path('api.json'));

        $spec = json_decode((string) file_get_contents($target), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('openapi', $spec);
        $this->assertNotEmpty($spec['paths']);

        $response = $this->get('/docs/api.json');

        $response->assertOk();
        $this->assertSame($spec, $response->json());
    }

    private function scheduledExportEvent(): Event
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'scramble:export'),
        ));

        $this->assertCount(1, $events);

        return $events[0];
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
