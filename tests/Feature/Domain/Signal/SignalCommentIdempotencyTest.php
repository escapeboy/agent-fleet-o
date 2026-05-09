<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\ApiTestCase;

class SignalCommentIdempotencyTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_no_idempotency_key_still_inserts_each_call(): void
    {
        $signal = $this->createBugReportSignal();
        $action = app(AddSignalCommentAction::class);

        $action->execute($signal, body: 'first', authorType: 'agent');
        $action->execute($signal, body: 'first', authorType: 'agent');

        $this->assertSame(
            2,
            SignalComment::query()->where('signal_id', $signal->id)->count(),
            'Without idempotency_key, the action must remain insert-on-every-call.',
        );
    }

    public function test_same_idempotency_key_dedupes_to_single_row(): void
    {
        $signal = $this->createBugReportSignal();
        $action = app(AddSignalCommentAction::class);

        $first = $action->execute(
            signal: $signal,
            body: 'Fix complete — PR #22 open for review',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:summary',
        );

        $second = $action->execute(
            signal: $signal,
            body: 'Fix complete — PR #22 open for review',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:summary',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            SignalComment::query()->where('signal_id', $signal->id)->count(),
        );
    }

    public function test_replace_true_updates_body_in_place(): void
    {
        $signal = $this->createBugReportSignal();
        $action = app(AddSignalCommentAction::class);

        $first = $action->execute(
            signal: $signal,
            body: 'Investigating…',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:summary',
        );

        $originalCreatedAt = $first->created_at;

        // Force a measurable updated_at delta.
        sleep(1);

        $second = $action->execute(
            signal: $signal,
            body: 'Fix complete — PR #22 open for review',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:summary',
            replace: true,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Fix complete — PR #22 open for review', $second->fresh()->body);
        $this->assertEquals(
            $originalCreatedAt->timestamp,
            $second->fresh()->created_at->timestamp,
            'created_at must be preserved when replacing.',
        );
        $this->assertGreaterThan(
            $originalCreatedAt->timestamp,
            $second->fresh()->updated_at->timestamp,
            'updated_at must advance when replacing.',
        );
        $this->assertSame(
            1,
            SignalComment::query()->where('signal_id', $signal->id)->count(),
        );
    }

    public function test_replace_false_default_returns_existing_without_change(): void
    {
        $signal = $this->createBugReportSignal();
        $action = app(AddSignalCommentAction::class);

        $first = $action->execute(
            signal: $signal,
            body: 'Investigating…',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:summary',
        );

        $second = $action->execute(
            signal: $signal,
            body: 'A different body that must NOT overwrite',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:summary',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Investigating…', $second->fresh()->body);
        $this->assertSame(
            1,
            SignalComment::query()->where('signal_id', $signal->id)->count(),
        );
    }

    public function test_different_keys_do_not_collide(): void
    {
        $signal = $this->createBugReportSignal();
        $action = app(AddSignalCommentAction::class);

        $action->execute(
            signal: $signal,
            body: 'summary',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:summary',
        );

        $action->execute(
            signal: $signal,
            body: 'stage:building',
            authorType: 'agent',
            idempotencyKey: 'experiment:abc:stage:building',
        );

        $this->assertSame(
            2,
            SignalComment::query()->where('signal_id', $signal->id)->count(),
        );
    }

    public function test_keys_are_scoped_per_signal(): void
    {
        $signalA = $this->createBugReportSignal();
        $signalB = $this->createBugReportSignal();
        $action = app(AddSignalCommentAction::class);

        $action->execute(
            signal: $signalA,
            body: 'on A',
            authorType: 'agent',
            idempotencyKey: 'shared-key',
        );

        $action->execute(
            signal: $signalB,
            body: 'on B',
            authorType: 'agent',
            idempotencyKey: 'shared-key',
        );

        $this->assertSame(1, SignalComment::query()->where('signal_id', $signalA->id)->count());
        $this->assertSame(1, SignalComment::query()->where('signal_id', $signalB->id)->count());
    }

    private function createBugReportSignal(SignalStatus $status = SignalStatus::Received): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'client-platform',
            'project_key' => 'client-platform',
            'status' => $status,
            'content_hash' => md5('bug_report-client-platform-'.microtime(true).'-'.bin2hex(random_bytes(4))),
            'received_at' => now(),
            'payload' => [
                'title' => 'Submit button broken',
                'description' => 'desc',
                'severity' => 'major',
                'url' => 'https://app.example.com/checkout',
                'reporter_id' => 'user-123',
                'reporter_name' => 'Alice',
                'action_log' => [],
                'console_log' => [],
                'network_log' => [],
                'browser' => 'Mozilla/5.0',
                'viewport' => '1440x900',
                'environment' => 'production',
            ],
            'tags' => ['bug_report', 'major'],
        ]);
    }
}
