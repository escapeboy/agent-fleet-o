<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Signal\Actions\ResolveStackTraceAction;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SourceMap;
use App\Domain\Signal\Services\SourceMapResolver;
use App\Domain\Signal\Services\StackTraceParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\V1\ApiTestCase;

class SourceMapResolutionTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // StackTraceParser tests

    public function test_parses_frame_with_function_name(): void
    {
        $parser = new StackTraceParser;
        $frames = $parser->parseFrames("at handleSubmit (app.js:10:20)\n");

        $this->assertCount(1, $frames);
        $this->assertEquals('app.js', $frames[0]['file']);
        $this->assertEquals(10, $frames[0]['line']);
        $this->assertEquals(20, $frames[0]['column']);
        $this->assertEquals('handleSubmit', $frames[0]['function']);
    }

    public function test_parses_anonymous_frame(): void
    {
        $parser = new StackTraceParser;
        $frames = $parser->parseFrames("at app.js:10:20\n");

        $this->assertCount(1, $frames);
        $this->assertEquals('app.js', $frames[0]['file']);
        $this->assertNull($frames[0]['function']);
    }

    public function test_strips_host_from_url_frames(): void
    {
        $parser = new StackTraceParser;
        $frames = $parser->parseFrames("at handleSubmit (https://app.example.com/widget.js:1:9999)\n");

        $this->assertCount(1, $frames);
        $this->assertEquals('widget.js', $frames[0]['file']);
    }

    public function test_is_project_frame_excludes_node_modules(): void
    {
        $parser = new StackTraceParser;

        $projectFrame = ['file' => 'app/components/Form.js', 'line' => 10, 'column' => 5, 'function' => null];
        $nodeFrame = ['file' => 'node_modules/axios/lib/Axios.js', 'line' => 10, 'column' => 5, 'function' => null];

        $this->assertTrue($parser->isProjectFrame($projectFrame));
        $this->assertFalse($parser->isProjectFrame($nodeFrame));
    }

    public function test_extract_errors_returns_only_error_level_entries(): void
    {
        $parser = new StackTraceParser;

        $consoleLog = [
            ['level' => 'log', 'message' => 'loaded'],
            ['level' => 'error', 'message' => "TypeError: null\nat fn (app.js:10:5)"],
            ['level' => 'warn', 'message' => 'deprecated'],
        ];

        $errors = $parser->extractErrors($consoleLog);

        $this->assertCount(1, $errors);
        $this->assertEquals('TypeError', $errors[0]['type']);
    }

    // SourceMapResolver tests

    public function test_source_map_resolver_returns_null_when_no_map_exists(): void
    {
        $resolver = app(SourceMapResolver::class);

        $result = $resolver->resolve('nonexistent-team', 'myapp', 'abc123', [
            'file' => 'app.js',
            'line' => 1,
            'column' => 99,
            'function' => null,
        ]);

        $this->assertNull($result);
    }

    public function test_resolves_minified_frame_to_original_source(): void
    {
        // Build a minimal source map with a known mapping
        // mappings: "AAAA" = genCol:0, src:0, origLine:0, origCol:0 (all deltas 0)
        // This maps (line 1, col 0) -> (sources[0], origLine 0, origCol 0)
        $mapData = [
            'version' => 3,
            'sources' => ['resources/js/components/ProfileForm.vue'],
            'names' => ['handleSubmit'],
            'mappings' => 'AAAA', // single segment: all zeros
        ];

        SourceMap::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'release' => 'abc123',
            'map_data' => $mapData,
        ]);

        $resolver = app(SourceMapResolver::class);

        $result = $resolver->resolve($this->team->id, 'myapp', 'abc123', [
            'file' => 'app.js',
            'line' => 1,
            'column' => 0,
            'function' => null,
        ]);

        $this->assertNotNull($result);
        $this->assertEquals('resources/js/components/ProfileForm.vue', $result['file']);
        $this->assertTrue($result['isProjectCode']);
    }

    // ResolveStackTraceAction tests

    public function test_resolve_stack_trace_action_writes_resolved_errors_to_payload(): void
    {
        // Create a source map
        $mapData = [
            'version' => 3,
            'sources' => ['app/js/Form.js'],
            'names' => [],
            'mappings' => 'AAAA',
        ];

        SourceMap::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'release' => 'abc123',
            'map_data' => $mapData,
        ]);

        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'myapp',
            'project_key' => 'myapp',
            'payload' => [
                'deploy_commit' => 'abc123',
                'console_log' => [
                    ['level' => 'error', 'message' => "TypeError: null\nat handleSubmit (app.js:1:0)"],
                ],
            ],
            'content_hash' => md5('test-resolve-'.uniqid()),
            'received_at' => now(),
        ]);

        app(ResolveStackTraceAction::class)->execute($signal);

        $signal->refresh();
        $this->assertArrayHasKey('resolved_errors', $signal->payload);
        $this->assertCount(1, $signal->payload['resolved_errors']);
    }

    public function test_resolve_stack_trace_action_skips_signal_with_no_console_log(): void
    {
        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'myapp',
            'payload' => ['title' => 'No logs'],
            'content_hash' => md5('test-no-log-'.uniqid()),
            'received_at' => now(),
        ]);

        // Should not throw
        app(ResolveStackTraceAction::class)->execute($signal);

        $signal->refresh();
        $this->assertArrayNotHasKey('resolved_errors', $signal->payload);
    }

    // SourceMapController tests

    public function test_source_map_upload_stores_map(): void
    {
        $this->actingAs($this->user);

        $mapData = ['version' => 3, 'sources' => ['app.js'], 'mappings' => 'AAAA'];

        $response = $this->postJson('/api/v1/source-maps', [
            'project' => 'myapp',
            'release' => 'abc123',
            'map_data' => $mapData,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'project', 'release']);

        $this->assertDatabaseHas('source_maps', [
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'release' => 'abc123',
        ]);
    }

    public function test_source_map_upload_upserts_existing(): void
    {
        $this->actingAs($this->user);

        $mapData = ['version' => 3, 'sources' => ['app.js'], 'mappings' => 'AAAA'];

        $this->postJson('/api/v1/source-maps', ['project' => 'myapp', 'release' => 'abc123', 'map_data' => $mapData])
            ->assertStatus(201);

        // Second upload for same project+release should upsert (not error)
        $this->postJson('/api/v1/source-maps', ['project' => 'myapp', 'release' => 'abc123', 'map_data' => $mapData])
            ->assertStatus(201);

        $this->assertDatabaseCount('source_maps', 1);
    }

    public function test_source_map_upload_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/source-maps', [
            'project' => 'myapp',
            'release' => 'abc123',
            'map_data' => ['version' => 3],
        ]);

        $response->assertStatus(401);
    }
}
