<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Livewire\Signals\BugReportDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportDetailPageAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Admin Attach Test',
            'slug' => 'admin-attach-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        Storage::fake(config('media-library.disk_name'));
    }

    private function createSignal(): Signal
    {
        return Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'bug_report',
            'source_identifier' => 'widget-'.uniqid('', true),
            'project_key' => 'acme',
            'status' => 'received',
            'payload' => ['title' => 't', 'reporter_id' => 'alice', 'reporter_name' => 'Alice'],
            'content_hash' => hash('sha256', 'attach-'.uniqid('', true)),
        ]);
    }

    public function test_admin_can_post_comment_with_image_attachment(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentText', 'here is the screenshot')
            ->set('commentImages', [UploadedFile::fake()->image('fix.png', 400, 300)])
            ->call('addComment')
            ->assertHasNoErrors()
            ->assertSet('commentText', '')
            ->assertSet('commentImages', []);

        $comment = SignalComment::where('signal_id', $signal->id)->sole();
        $this->assertSame('here is the screenshot', $comment->body);
        $this->assertCount(1, $comment->getMedia('attachments'));
    }

    public function test_admin_can_post_image_only_comment_with_empty_body(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentText', '')
            ->set('commentImages', [UploadedFile::fake()->image('only.png', 200, 200)])
            ->call('addComment')
            ->assertHasNoErrors();

        $comment = SignalComment::where('signal_id', $signal->id)->sole();
        $this->assertSame('', $comment->body);
        $this->assertCount(1, $comment->getMedia('attachments'));
    }

    public function test_admin_rejects_comment_with_neither_text_nor_images(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentText', '   ')
            ->set('commentImages', [])
            ->call('addComment')
            ->assertHasErrors('commentText');

        $this->assertSame(0, SignalComment::where('signal_id', $signal->id)->count());
    }

    public function test_admin_rejects_more_than_max_images(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentText', 'too many')
            ->set('commentImages', array_fill(0, 5, UploadedFile::fake()->image('x.png', 100, 100)))
            ->call('addComment')
            ->assertHasErrors('commentImages');

        $this->assertSame(0, SignalComment::where('signal_id', $signal->id)->count());
    }

    public function test_admin_rejects_non_image_file(): void
    {
        // Note: Livewire's TemporaryUploadedFile returns size=0 in tests when
        // `UploadedFile::fake()->create(...)` is used, which makes the `max:`
        // rule untestable via the Livewire harness. The `max:` rule is still
        // enforced at runtime and is exercised by BugReportWidgetCommentsTest
        // on the HTTP side, which shares the same config flags. This test
        // covers the mime/image rule branch of the same validation block.
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentText', 'payload')
            ->set('commentImages', [
                UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
            ])
            ->call('addComment')
            ->assertHasErrors('commentImages.0');

        $this->assertSame(0, SignalComment::where('signal_id', $signal->id)->count());
    }

    public function test_admin_can_remove_staged_image_before_posting(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentImages', [
                UploadedFile::fake()->image('a.png', 100, 100),
                UploadedFile::fake()->image('b.png', 100, 100),
                UploadedFile::fake()->image('c.png', 100, 100),
            ])
            ->call('removeCommentImage', 1)
            ->assertCount('commentImages', 2);
    }

    public function test_comment_list_renders_attachment_thumbnails(): void
    {
        $signal = $this->createSignal();

        // First post a comment with an image so the list has something to render.
        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentText', 'with pic')
            ->set('commentImages', [UploadedFile::fake()->image('s.png', 300, 200)])
            ->call('addComment')
            ->assertHasNoErrors();

        $comment = SignalComment::where('signal_id', $signal->id)->sole();
        $media = $comment->getMedia('attachments')->first();
        $this->assertNotNull($media);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->assertSeeHtml($media->getFullUrl())
            ->assertSeeHtml('loading="lazy"');
    }
}
