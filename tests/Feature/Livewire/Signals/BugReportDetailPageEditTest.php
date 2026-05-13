<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Livewire\Signals\BugReportDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportDetailPageEditTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Edit Test '.bin2hex(random_bytes(3)),
            'slug' => 'edit-test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        Storage::fake(config('media-library.disk_name'));
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
                'title' => 'Original Title',
                'description' => 'Original description',
                'reporter_name' => 'Alice',
            ], $payload),
            'content_hash' => hash('sha256', 'edit-'.uniqid('', true)),
        ]);
    }

    public function test_open_edit_modal_populates_fields(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openEditModal')
            ->assertSet('showEditModal', true)
            ->assertSet('editTitle', 'Original Title')
            ->assertSet('editDescription', 'Original description');
    }

    public function test_save_edit_updates_title_and_description(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openEditModal')
            ->set('editTitle', 'Updated Title')
            ->set('editDescription', 'Updated description text')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showEditModal', false);

        $signal->refresh();
        $this->assertSame('Updated Title', $signal->payload['title']);
        $this->assertSame('Updated description text', $signal->payload['description']);
    }

    public function test_save_edit_rejects_empty_title(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openEditModal')
            ->set('editTitle', '')
            ->call('saveEdit')
            ->assertHasErrors(['editTitle']);

        $signal->refresh();
        $this->assertSame('Original Title', $signal->payload['title']);
    }

    public function test_save_edit_rejects_non_image_attachment(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openEditModal')
            ->set('editTitle', 'Fine Title')
            ->set('editAttachment', UploadedFile::fake()->create('virus.exe', 1, 'application/octet-stream'))
            ->call('saveEdit')
            ->assertHasErrors(['editAttachment']);
    }

    public function test_save_edit_replaces_attachment(): void
    {
        $signal = $this->createSignal();

        // Attach an initial image via addMediaFromString to avoid temp-file path issues
        $signal->addMediaFromString(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQAABjE+ibYAAAAASUVORK5CYII='))
            ->usingFileName('old.png')
            ->toMediaCollection('bug_report_files');

        $this->assertCount(1, $signal->fresh()->getMedia('bug_report_files'));

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openEditModal')
            ->set('editTitle', 'Same Title')
            ->set('editAttachment', UploadedFile::fake()->image('new.png', 200, 200))
            ->call('saveEdit')
            ->assertHasNoErrors();

        $media = $signal->fresh()->getMedia('bug_report_files');
        $this->assertCount(1, $media);
        $this->assertStringEndsWith('.png', $media->first()->file_name);
    }

    public function test_save_edit_without_attachment_preserves_existing_media(): void
    {
        $signal = $this->createSignal();

        $signal->addMediaFromString(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQAABjE+ibYAAAAASUVORK5CYII='))
            ->usingFileName('keep.png')
            ->toMediaCollection('bug_report_files');

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openEditModal')
            ->set('editTitle', 'New Title Only')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertCount(1, $signal->fresh()->getMedia('bug_report_files'));
    }

    public function test_reopen_button_shown_for_resolved_signal(): void
    {
        $signal = $this->createSignal(['title' => 'Resolved Bug']);
        $signal->update(['status' => SignalStatus::Resolved]);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->assertSee('Reopen');
    }

    public function test_reopen_button_shown_for_dismissed_signal(): void
    {
        $signal = $this->createSignal(['title' => 'Dismissed Bug']);
        $signal->update(['status' => SignalStatus::Dismissed]);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->assertSee('Reopen');
    }

    public function test_update_status_reopens_resolved_to_triaged(): void
    {
        $signal = $this->createSignal();
        $signal->update(['status' => SignalStatus::Resolved]);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('updateStatus', 'triaged')
            ->assertHasNoErrors();

        $this->assertSame(SignalStatus::Triaged, $signal->fresh()->status);
    }
}
