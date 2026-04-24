<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\Shared\Models\Team;
use App\Livewire\Agents\AgentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AgentChatProtocolTabTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Tab Test',
            'slug' => 'tab-test-'.Str::random(4),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Target Agent',
            'slug' => 'target-'.Str::random(4),
            'role' => 'assistant',
            'goal' => 'respond',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
        ]);

        $this->actingAs($this->user);
    }

    public function test_publish_private_enables_protocol(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('publishChatProtocol', 'private');

        $this->agent->refresh();
        $this->assertTrue($this->agent->chat_protocol_enabled);
        $this->assertSame(AgentChatVisibility::Private->value, $this->agent->chat_protocol_visibility->value);
        $this->assertNotEmpty($this->agent->chat_protocol_slug);
    }

    public function test_publish_public_generates_secret(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('publishChatProtocol', 'public');

        $this->agent->refresh();
        $this->assertTrue($this->agent->chat_protocol_enabled);
        $this->assertNotEmpty($this->agent->chat_protocol_secret);
    }

    public function test_revoke_disables_protocol(): void
    {
        $this->agent->update([
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => AgentChatVisibility::Public->value,
            'chat_protocol_slug' => 'test-slug',
        ]);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('revokeChatProtocol');

        $this->agent->refresh();
        $this->assertFalse($this->agent->chat_protocol_enabled);
    }

    public function test_rotate_secret_produces_new_value(): void
    {
        $this->agent->update([
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => AgentChatVisibility::Public->value,
            'chat_protocol_slug' => 'rot-slug',
            'chat_protocol_secret' => 'old-secret-value',
        ]);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('rotateChatProtocolSecret');

        $this->agent->refresh();
        $this->assertNotSame('old-secret-value', $this->agent->chat_protocol_secret);
        $this->assertGreaterThan(32, strlen((string) $this->agent->chat_protocol_secret));
    }

    public function test_invalid_visibility_rejected(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('publishChatProtocol', 'bogus');

        $this->agent->refresh();
        $this->assertFalse($this->agent->chat_protocol_enabled);
    }
}
