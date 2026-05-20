<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Mcp\Tools\Signal\BugReportAddCommentTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class BugReportAddCommentToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Comment Tool Team',
            'slug' => 'comment-tool-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_idempotency_key_dedupes_via_mcp_tool(): void
    {
        $signal = $this->makeBugReport();
        $tool = new BugReportAddCommentTool;

        $first = $this->decode($tool->handle(new Request([
            'signal_id' => $signal->id,
            'body' => 'Fix complete — PR #22',
            'idempotency_key' => 'experiment:abc:summary',
        ])));

        $second = $this->decode($tool->handle(new Request([
            'signal_id' => $signal->id,
            'body' => 'Fix complete — PR #22',
            'idempotency_key' => 'experiment:abc:summary',
        ])));

        $this->assertSame($first['comment_id'], $second['comment_id']);
        $this->assertTrue($second['deduped'], 'Second call must report deduped=true.');
        $this->assertFalse($first['deduped'], 'First call must report deduped=false.');
        $this->assertSame(1, SignalComment::query()->where('signal_id', $signal->id)->count());
    }

    public function test_replace_true_updates_body_via_mcp_tool(): void
    {
        $signal = $this->makeBugReport();
        $tool = new BugReportAddCommentTool;

        $tool->handle(new Request([
            'signal_id' => $signal->id,
            'body' => 'Investigating…',
            'idempotency_key' => 'experiment:abc:summary',
        ]));

        $second = $this->decode($tool->handle(new Request([
            'signal_id' => $signal->id,
            'body' => 'Final summary',
            'idempotency_key' => 'experiment:abc:summary',
            'replace' => true,
        ])));

        $this->assertSame('Final summary', $second['body']);
        $this->assertSame(1, SignalComment::query()->where('signal_id', $signal->id)->count());
    }

    public function test_no_key_inserts_each_call_via_mcp_tool(): void
    {
        $signal = $this->makeBugReport();
        $tool = new BugReportAddCommentTool;

        $tool->handle(new Request([
            'signal_id' => $signal->id,
            'body' => 'first',
        ]));

        $tool->handle(new Request([
            'signal_id' => $signal->id,
            'body' => 'first',
        ]));

        $this->assertSame(2, SignalComment::query()->where('signal_id', $signal->id)->count());
    }

    private function makeBugReport(): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'client-platform',
            'project_key' => 'client-platform',
            'status' => SignalStatus::Received,
            'content_hash' => md5('bug_report-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'title' => 'x',
                'description' => 'y',
                'severity' => 'major',
                'url' => 'https://app.example.com/x',
                'reporter_id' => 'r',
                'reporter_name' => 'n',
                'action_log' => [],
                'console_log' => [],
                'network_log' => [],
                'browser' => 'b',
                'viewport' => 'v',
                'environment' => 'production',
            ],
            'tags' => ['bug_report'],
        ]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
