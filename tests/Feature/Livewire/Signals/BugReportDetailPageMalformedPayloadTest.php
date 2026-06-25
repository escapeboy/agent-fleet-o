<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Livewire\Signals\BugReportDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BugReportDetailPageMalformedPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Malformed Test '.bin2hex(random_bytes(3)),
            'slug' => 'malformed-test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function createSignal(array $payload = []): Signal
    {
        return Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'bug_report',
            'source_identifier' => 'widget-'.uniqid('', true),
            'project_key' => 'acme',
            'status' => 'received',
            'payload' => array_merge([
                'title' => 'Malformed Bug',
                'description' => 'A report with a legacy payload',
            ], $payload),
            'content_hash' => hash('sha256', 'malformed-'.uniqid('', true)),
        ]);
    }

    /**
     * A legacy/malformed payload can store a log section as a JSON string rather
     * than an array. count()/@foreach over a string throws a TypeError and 500s
     * the whole detail page — the view must coerce these to arrays first.
     *
     * @return array<string, array{0: string}>
     */
    public static function logSectionProvider(): array
    {
        return [
            'breadcrumbs as string' => ['breadcrumbs'],
            'action_log as string' => ['action_log'],
            'console_log as string' => ['console_log'],
            'network_log as string' => ['network_log'],
        ];
    }

    #[DataProvider('logSectionProvider')]
    public function test_renders_when_log_section_is_a_string(string $section): void
    {
        $signal = $this->createSignal([
            $section => '[{"this":"is a json string, not an array"}]',
        ]);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->assertOk()
            ->assertSee('Malformed Bug');
    }

    public function test_renders_when_all_log_sections_are_strings(): void
    {
        $signal = $this->createSignal([
            'breadcrumbs' => 'not-an-array',
            'action_log' => 'not-an-array',
            'console_log' => 'not-an-array',
            'network_log' => 'not-an-array',
        ]);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->assertOk()
            ->assertSee('Malformed Bug');
    }

    public function test_renders_well_formed_breadcrumbs(): void
    {
        $signal = $this->createSignal([
            'breadcrumbs' => [
                ['timestamp' => '12:00:00', 'category' => 'navigation', 'data' => ['from' => '/a', 'to' => '/b']],
            ],
        ]);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->assertOk()
            ->assertSee('Breadcrumbs (1)');
    }
}
