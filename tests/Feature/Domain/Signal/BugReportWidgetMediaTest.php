<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class BugReportWidgetMediaTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media-library.disk_name' => 'local']);
        Storage::fake('local');
        RateLimiter::clear('widget-media:');

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'WidgetMedia Team',
            'slug' => 'widget-media-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
    }

    public function test_streams_media_for_widget_visible_comment(): void
    {
        $signal = $this->bugReport();
        $media = $this->attachImage($this->comment($signal, true));

        $this->get($this->url($signal, $media))
            ->assertOk()
            ->assertHeader('access-control-allow-origin', '*');
    }

    public function test_rejects_invalid_public_key(): void
    {
        $signal = $this->bugReport();
        $media = $this->attachImage($this->comment($signal, true));

        $this->get(sprintf(
            '/api/public/widget/bug-report/%s/media/%s?team_public_key=wk_bad',
            $signal->id,
            $media->id,
        ))->assertStatus(401);
    }

    public function test_rejects_media_from_foreign_signal(): void
    {
        $signalA = $this->bugReport();
        $signalB = $this->bugReport();
        $media = $this->attachImage($this->comment($signalA, true));

        // Media belongs to signal A's comment; request it under signal B.
        $this->get($this->url($signalB, $media))->assertNotFound();
    }

    public function test_rejects_media_of_non_widget_visible_comment(): void
    {
        $signal = $this->bugReport();
        $media = $this->attachImage($this->comment($signal, false));

        $this->get($this->url($signal, $media))->assertNotFound();
    }

    public function test_rejects_disallowed_conversion(): void
    {
        $signal = $this->bugReport();
        $media = $this->attachImage($this->comment($signal, true));

        $this->get($this->url($signal, $media).'&conversion=original')->assertNotFound();
    }

    public function test_list_returns_streaming_media_url(): void
    {
        $signal = $this->bugReport();
        $this->attachImage($this->comment($signal, true));

        $response = $this->getJson(sprintf(
            '/api/public/widget/bug-report/%s/comments?team_public_key=%s',
            $signal->id,
            $this->team->widget_public_key,
        ))->assertOk();

        $url = $response->json('comments.0.attachments.0.url');
        $this->assertStringContainsString('/media/', $url);
        $this->assertStringNotContainsString('/storage/', $url);
        $this->assertStringNotContainsString('/files/', $url);
    }

    private function url(Signal $signal, Media $media): string
    {
        return sprintf(
            '/api/public/widget/bug-report/%s/media/%s?team_public_key=%s',
            $signal->id,
            $media->id,
            $this->team->widget_public_key,
        );
    }

    private function bugReport(): Signal
    {
        $payload = ['title' => 'Broken', 'severity' => 'major'];

        return Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'bug_report',
            'source_identifier' => 'widget-'.uniqid('', true),
            'status' => 'received',
            'payload' => $payload,
            'content_hash' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function comment(Signal $signal, bool $visible): SignalComment
    {
        return SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'reporter',
            'body' => 'see screenshot',
            'widget_visible' => $visible,
        ]);
    }

    private function attachImage(SignalComment $comment): Media
    {
        $file = UploadedFile::fake()->image('shot.png', 120, 120);

        return $comment->addMedia($file)->toMediaCollection('attachments');
    }
}
