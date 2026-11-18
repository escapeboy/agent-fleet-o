<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Models\ConnectorBinding;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestSignalNullByteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $tags
     */
    private function ingest(Team $team, array $payload, array $tags = [], ?string $nativeId = null, string $sourceIdentifier = 'app'): ?Signal
    {
        return app(IngestSignalAction::class)->execute(
            sourceType: 'sentry',
            sourceIdentifier: $sourceIdentifier,
            payload: $payload,
            tags: $tags,
            sourceNativeId: $nativeId,
            teamId: $team->id,
        );
    }

    private function assertNoNul(mixed $value): void
    {
        $encoded = json_encode($value);
        $this->assertNotFalse($encoded);
        $this->assertStringNotContainsString('\u0000', $encoded);
        $this->assertStringNotContainsString("\0", $encoded);
    }

    public function test_new_signal_with_nested_nul_is_stored_without_nul(): void
    {
        $team = Team::factory()->create();

        $signal = $this->ingest(
            $team,
            ['title' => 'Crash', 'exception' => ['values' => [["fra\0me" => "va\0lue"]]]],
            ["env:\0prod"],
            "ISSUE-\0X",
            "proj\0ect",
        );

        $this->assertNotNull($signal);
        $stored = Signal::findOrFail($signal->id);

        $this->assertSame('value', $stored->payload['exception']['values'][0]['frame']);
        $this->assertSame(['env:prod'], $stored->tags);
        $this->assertSame('ISSUE-X', $stored->source_native_id);
        $this->assertSame('project', $stored->source_identifier);
        $this->assertNoNul($stored->payload);
    }

    public function test_duplicate_with_nul_merges_cleanly(): void
    {
        $team = Team::factory()->create();

        $first = $this->ingest($team, ['title' => 'NPE'], ['a'], 'ISSUE-1');
        $second = $this->ingest($team, ['title' => "NPE\0 again", "ex\0tra" => "v\0"], ["b\0"], 'ISSUE-1');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);

        $stored = Signal::findOrFail($first->id);
        $this->assertSame(1, $stored->duplicate_count);
        $this->assertSame('NPE again', $stored->payload['title']);
        $this->assertSame('v', $stored->payload['extra']);
        $this->assertSame(['a', 'b'], $stored->tags);
        $this->assertNoNul($stored->payload);
    }

    public function test_content_hash_is_computed_on_cleaned_payload(): void
    {
        $team = Team::factory()->create();

        $first = $this->ingest($team, ['msg' => "a\0b"]);
        $this->assertNotNull($first);
        $this->assertSame(hash('sha256', (string) json_encode(['msg' => 'ab'])), $first->content_hash);

        $second = $this->ingest($team, ['msg' => 'ab']);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Signal::where('team_id', $team->id)->count());
    }

    public function test_sender_hints_with_nul_are_cleaned_before_binding_is_stored(): void
    {
        $team = Team::factory()->create();

        $signal = app(IngestSignalAction::class)->execute(
            sourceType: 'telegram',
            sourceIdentifier: '12345',
            payload: ['text' => 'hi'],
            teamId: $team->id,
            senderHints: ['name' => "Jo\0hn", 'email' => "jo\0hn@example.com"],
        );

        $this->assertNull($signal);

        $binding = ConnectorBinding::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('external_id', '12345')
            ->firstOrFail();

        $this->assertSame('John', $binding->external_name);
        $this->assertSame('john@example.com', $binding->metadata['email']);
    }

    public function test_invalid_utf8_payloads_do_not_collide(): void
    {
        $team = Team::factory()->create();

        $one = $this->ingest($team, ['msg' => "bad\xC3\x28 one"]);
        $two = $this->ingest($team, ['msg' => "bad\xC3\x28 two"]);

        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertNotSame($one->id, $two->id);
        $this->assertSame(2, Signal::where('team_id', $team->id)->count());

        foreach ([$one, $two] as $signal) {
            $this->assertNotSame(hash('sha256', ''), $signal->content_hash);
            $stored = Signal::findOrFail($signal->id);
            $this->assertTrue(mb_check_encoding($stored->payload['msg'], 'UTF-8'));
        }
        $this->assertStringContainsString('one', Signal::findOrFail($one->id)->payload['msg']);
    }
}
