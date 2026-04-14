<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Signal\Actions\AnalyzeSuspectFilesAction;
use App\Domain\Signal\Models\RouteMap;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SuspectFilesAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\V1\ApiTestCase;

class SuspectFilesTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // SuspectFilesAnalyzer tests

    public function test_adds_first_project_frame_with_high_confidence(): void
    {
        $analyzer = app(SuspectFilesAnalyzer::class);

        $payload = [
            'url' => 'https://app.example.com/settings/profile',
            'resolved_errors' => [
                [
                    'resolved_frames' => [
                        ['file' => 'resources/js/ProfileForm.vue', 'isProjectCode' => true, 'line' => 87],
                        ['file' => 'node_modules/axios/Axios.js', 'isProjectCode' => false, 'line' => 10],
                    ],
                ],
            ],
        ];

        $result = $analyzer->analyze($payload, $this->team->id, 'myapp');

        $this->assertNotEmpty($result['suspect_files']);
        $top = $result['suspect_files'][0];
        $this->assertEquals('resources/js/ProfileForm.vue', $top['path']);
        $this->assertEquals(0.95, $top['confidence']);
    }

    public function test_adds_route_controller_when_route_map_matched(): void
    {
        RouteMap::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'release' => 'abc123',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => '/settings/profile',
                    'controller' => 'App\\Http\\Controllers\\SettingsController@profile',
                    'livewire_component' => 'App\\Livewire\\SettingsPage',
                ],
            ],
        ]);

        $analyzer = app(SuspectFilesAnalyzer::class);

        $result = $analyzer->analyze(
            ['url' => 'https://app.example.com/settings/profile', 'resolved_errors' => []],
            $this->team->id,
            'myapp',
        );

        $paths = array_column($result['suspect_files'], 'path');

        $this->assertContains('app/Http/Controllers/SettingsController.php', $paths);
        $this->assertContains('app/Livewire/SettingsPage.php', $paths);
        $this->assertNotEmpty($result['source_hints']['route'] ?? []);
    }

    public function test_deduplicates_and_keeps_highest_confidence(): void
    {
        $analyzer = app(SuspectFilesAnalyzer::class);

        // Provide same file from two sources (stack firstProjectFrame=0.95, Livewire=0.9)
        // After dedup, the highest confidence (0.95) should be kept
        $payload = [
            'url' => 'https://app.example.com/profile',
            'resolved_errors' => [
                [
                    'resolved_frames' => [
                        ['file' => 'app/Livewire/ProfileForm.php', 'isProjectCode' => true, 'line' => 10],
                    ],
                ],
            ],
            'livewire_components' => json_encode([
                ['class' => 'App\\Livewire\\ProfileForm'],
            ]),
        ];

        $result = $analyzer->analyze($payload, $this->team->id, null);

        $matches = array_filter($result['suspect_files'], fn ($f) => str_contains($f['path'], 'ProfileForm'));
        $this->assertCount(1, $matches); // deduplicated
        $this->assertEquals(0.95, array_values($matches)[0]['confidence']); // highest wins
    }

    public function test_sorts_results_by_confidence_descending(): void
    {
        RouteMap::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'release' => 'v1',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => '/checkout',
                    'controller' => 'App\\Http\\Controllers\\CheckoutController@index',
                    'livewire_component' => null,
                ],
            ],
        ]);

        $analyzer = app(SuspectFilesAnalyzer::class);

        $payload = [
            'url' => 'https://app.example.com/checkout',
            'resolved_errors' => [
                [
                    'resolved_frames' => [
                        ['file' => 'resources/js/Checkout.js', 'isProjectCode' => true, 'line' => 50],
                    ],
                ],
            ],
        ];

        $result = $analyzer->analyze($payload, $this->team->id, 'myapp');

        $confidences = array_column($result['suspect_files'], 'confidence');
        $sorted = $confidences;
        rsort($sorted);
        $this->assertEquals($sorted, $confidences);
    }

    public function test_uri_match_works_when_route_has_no_leading_slash(): void
    {
        RouteMap::create([
            'team_id' => $this->team->id,
            'project' => 'barsy',
            'release' => 'v1',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => 'bug-reports/{signal}',
                    'controller' => 'App\\Http\\Controllers\\BugReportController@show',
                    'livewire_component' => null,
                    'name' => 'bug-reports.show',
                ],
            ],
        ]);

        $analyzer = app(SuspectFilesAnalyzer::class);

        $result = $analyzer->analyze(
            ['url' => 'https://app.barsy.dev/bug-reports/abc-123', 'resolved_errors' => []],
            $this->team->id,
            'barsy',
        );

        $paths = array_column($result['suspect_files'], 'path');
        $this->assertContains('app/Http/Controllers/BugReportController.php', $paths);
    }

    public function test_route_name_fallback_matches_when_url_path_would_fail(): void
    {
        RouteMap::create([
            'team_id' => $this->team->id,
            'project' => 'barsy',
            'release' => 'v1',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => 'bug-reports/{signal}',
                    'controller' => 'App\\Http\\Controllers\\BugReportController@show',
                    'livewire_component' => null,
                    'name' => 'bug-reports.show',
                ],
            ],
        ]);

        $analyzer = app(SuspectFilesAnalyzer::class);

        // Pass a URL that would not match (e.g. absolute path confusion) but correct route_name
        $result = $analyzer->analyze(
            [
                'url' => 'https://app.barsy.dev/some/unresolvable/path',
                'route_name' => 'bug-reports.show',
                'resolved_errors' => [],
            ],
            $this->team->id,
            'barsy',
        );

        $paths = array_column($result['suspect_files'], 'path');
        $this->assertContains('app/Http/Controllers/BugReportController.php', $paths);
    }

    // AnalyzeSuspectFilesAction tests

    public function test_analyze_action_writes_suspect_files_to_signal_payload(): void
    {
        RouteMap::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'release' => 'v1',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => '/profile',
                    'controller' => 'App\\Http\\Controllers\\ProfileController@show',
                    'livewire_component' => null,
                ],
            ],
        ]);

        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'myapp',
            'project_key' => 'myapp',
            'payload' => [
                'url' => 'https://app.example.com/profile',
            ],
            'content_hash' => md5('suspect-files-test-'.uniqid()),
            'received_at' => now(),
        ]);

        app(AnalyzeSuspectFilesAction::class)->execute($signal);

        $signal->refresh();
        $this->assertArrayHasKey('suspect_files', $signal->payload);
        $this->assertNotEmpty($signal->payload['suspect_files']);
    }

    // RouteMapController tests

    public function test_route_map_store_creates_entry(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/v1/route-maps', [
            'project' => 'myapp',
            'release' => 'abc123',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => '/settings/profile',
                    'controller' => 'App\\Http\\Controllers\\SettingsController@profile',
                    'livewire_component' => 'App\\Livewire\\SettingsPage',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('project', 'myapp')
            ->assertJsonPath('routes_count', 1);

        $this->assertDatabaseHas('route_maps', [
            'team_id' => $this->team->id,
            'project' => 'myapp',
        ]);
    }

    public function test_route_map_lookup_returns_matching_route(): void
    {
        $this->actingAs($this->user);

        RouteMap::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'release' => 'v1',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => '/settings/profile',
                    'controller' => 'App\\Http\\Controllers\\SettingsController@profile',
                ],
            ],
        ]);

        $response = $this->getJson('/api/v1/route-maps/lookup?project=myapp&url=https://app.example.com/settings/profile');

        $response->assertStatus(200)
            ->assertJsonPath('route.uri', '/settings/profile');
    }

    public function test_route_map_lookup_returns_404_when_no_match(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/v1/route-maps/lookup?project=myapp&url=/does-not-exist');

        $response->assertStatus(404);
    }

    public function test_route_map_upserts_on_same_project(): void
    {
        $this->actingAs($this->user);

        $this->postJson('/api/v1/route-maps', [
            'project' => 'myapp',
            'release' => 'v1',
            'routes' => [['method' => 'GET', 'uri' => '/old']],
        ])->assertStatus(201);

        $this->postJson('/api/v1/route-maps', [
            'project' => 'myapp',
            'release' => 'v2',
            'routes' => [['method' => 'GET', 'uri' => '/new']],
        ])->assertStatus(201);

        $this->assertDatabaseCount('route_maps', 1);
    }
}
