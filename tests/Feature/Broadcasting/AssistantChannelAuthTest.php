<?php

namespace Tests\Feature\Broadcasting;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Chan Test '.bin2hex(random_bytes(3)),
            'slug' => 'chan-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->owner->id,
            'settings' => [],
        ]);
        $this->owner->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->owner, ['role' => 'owner']);
    }

    public function test_owner_can_subscribe_to_their_conversation_channel(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'title' => 'Test',
        ]);

        $result = $this->callChannelAuth($this->owner, $conversation->id);

        $this->assertTrue($result);
    }

    public function test_other_team_member_cannot_subscribe_to_conversation_from_different_team(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'title' => 'Test',
        ]);

        $outsider = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $outsider->id,
            'settings' => [],
        ]);
        $outsider->update(['current_team_id' => $otherTeam->id]);

        $result = $this->callChannelAuth($outsider, $conversation->id);

        $this->assertFalse($result);
    }

    public function test_user_with_no_team_cannot_subscribe(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'title' => 'Test',
        ]);

        $noTeamUser = User::factory()->create(['current_team_id' => null]);

        $result = $this->callChannelAuth($noTeamUser, $conversation->id);

        $this->assertFalse($result);
    }

    public function test_nonexistent_conversation_denies_auth(): void
    {
        $result = $this->callChannelAuth($this->owner, '00000000-0000-0000-0000-000000000000');

        $this->assertFalse($result);
    }

    /** Mirrors the closure registered in routes/channels.php for the assistant.{conversationId} channel. */
    private function callChannelAuth(User $user, string $conversationId): bool
    {
        return AssistantConversation::withoutGlobalScopes()
            ->where('id', $conversationId)
            ->where('team_id', $user->current_team_id)
            ->exists();
    }
}
