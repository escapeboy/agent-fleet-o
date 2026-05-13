<?php

namespace Tests\Feature\Livewire\Assistant;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Shared\Models\Team;
use App\Livewire\Assistant\AssistantPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AssistantPanelStreamingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Stream Test '.bin2hex(random_bytes(3)),
            'slug' => 'stream-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);
    }

    public function test_receive_stream_chunk_updates_last_message_content(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);

        $placeholder = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'team_id' => $this->team->id,
            'role' => 'assistant',
            'content' => '',
            'metadata' => ['status' => 'streaming'],
            'created_at' => now(),
        ]);

        $component = Livewire::test(AssistantPanel::class)
            ->set('conversationId', $conversation->id)
            ->set('pendingMessageId', $placeholder->id)
            ->set('messages', [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => null, 'pending' => true, 'streaming' => false, 'tool_calls_in_progress' => []],
            ]);

        $component->call('receiveStreamChunk', [
            'placeholderId' => $placeholder->id,
            'content' => 'Partial reply...',
            'toolCallsInProgress' => [],
        ]);

        $messages = $component->get('messages');
        $lastMessage = end($messages);

        $this->assertSame('Partial reply...', $lastMessage['content']);
        $this->assertTrue($lastMessage['streaming']);
        $this->assertTrue($lastMessage['pending']);
    }

    public function test_receive_stream_chunk_ignores_mismatched_placeholder(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);

        $component = Livewire::test(AssistantPanel::class)
            ->set('conversationId', $conversation->id)
            ->set('pendingMessageId', 'the-real-placeholder-id')
            ->set('messages', [
                ['role' => 'assistant', 'content' => 'original', 'pending' => true, 'streaming' => false, 'tool_calls_in_progress' => []],
            ]);

        $component->call('receiveStreamChunk', [
            'placeholderId' => 'some-other-placeholder',
            'content' => 'Should not appear',
            'toolCallsInProgress' => [],
        ]);

        $messages = $component->get('messages');
        $lastMessage = end($messages);

        $this->assertSame('original', $lastMessage['content']);
    }

    public function test_receive_stream_chunk_ignores_when_no_pending_message(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);

        $component = Livewire::test(AssistantPanel::class)
            ->set('conversationId', $conversation->id)
            ->set('pendingMessageId', '')
            ->set('messages', [
                ['role' => 'assistant', 'content' => 'settled', 'pending' => false],
            ]);

        $component->call('receiveStreamChunk', [
            'placeholderId' => 'msg-123',
            'content' => 'ghost chunk',
            'toolCallsInProgress' => [],
        ]);

        $messages = $component->get('messages');
        $lastMessage = end($messages);

        $this->assertSame('settled', $lastMessage['content']);
    }

    public function test_receive_stream_chunk_includes_tool_calls_in_progress(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);

        $placeholder = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'team_id' => $this->team->id,
            'role' => 'assistant',
            'content' => '',
            'metadata' => ['status' => 'streaming'],
            'created_at' => now(),
        ]);

        $component = Livewire::test(AssistantPanel::class)
            ->set('conversationId', $conversation->id)
            ->set('pendingMessageId', $placeholder->id)
            ->set('messages', [
                ['role' => 'assistant', 'content' => null, 'pending' => true, 'streaming' => false, 'tool_calls_in_progress' => []],
            ]);

        $component->call('receiveStreamChunk', [
            'placeholderId' => $placeholder->id,
            'content' => '',
            'toolCallsInProgress' => ['list_agents', 'search_signals'],
        ]);

        $messages = $component->get('messages');
        $lastMessage = end($messages);

        $this->assertSame(['list_agents', 'search_signals'], $lastMessage['tool_calls_in_progress']);
    }
}
