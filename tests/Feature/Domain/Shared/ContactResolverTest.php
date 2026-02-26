<?php

namespace Tests\Feature\Domain\Shared;

use App\Domain\Shared\Models\ContactChannel;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\ContactResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactResolverTest extends TestCase
{
    use RefreshDatabase;

    private ContactResolver $resolver;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(ContactResolver::class);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_resolve_or_create_creates_new_identity_for_unknown_channel(): void
    {
        $identity = $this->resolver->resolveOrCreate(
            teamId: $this->team->id,
            channel: 'telegram',
            externalId: '123456789',
            hints: ['name' => 'John Doe'],
        );

        $this->assertInstanceOf(ContactIdentity::class, $identity);
        $this->assertEquals($this->team->id, $identity->team_id);
        $this->assertEquals('John Doe', $identity->display_name);

        // Channel record should exist
        $channel = ContactChannel::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('channel', 'telegram')
            ->where('external_id', '123456789')
            ->first();
        $this->assertNotNull($channel);
        $this->assertEquals($identity->id, $channel->contact_identity_id);
    }

    public function test_resolve_or_create_returns_existing_identity_on_second_call(): void
    {
        $first = $this->resolver->resolveOrCreate(
            teamId: $this->team->id,
            channel: 'telegram',
            externalId: '111222333',
        );

        $second = $this->resolver->resolveOrCreate(
            teamId: $this->team->id,
            channel: 'telegram',
            externalId: '111222333',
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, ContactIdentity::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_phone_normalization_merges_identity_for_same_phone(): void
    {
        // Create an identity with a known phone
        $existing = ContactIdentity::create([
            'team_id' => $this->team->id,
            'display_name' => 'Jane',
            'phone' => '+12125551234',
        ]);

        // Resolve with a WhatsApp channel that sends the phone without +
        $resolved = $this->resolver->resolveOrCreate(
            teamId: $this->team->id,
            channel: 'whatsapp',
            externalId: 'whatsapp-ext-id',
            hints: ['phone' => '12125551234'],
        );

        // Should match the existing identity by phone
        $this->assertEquals($existing->id, $resolved->id);
    }

    public function test_e164_phone_is_stored_correctly(): void
    {
        $identity = $this->resolver->resolveOrCreate(
            teamId: $this->team->id,
            channel: 'signal',
            externalId: '+4412345678',
            hints: ['phone' => '+4412345678'],
        );

        $identity->refresh();
        $this->assertEquals('+4412345678', $identity->phone);
    }

    public function test_merge_moves_channels_to_target(): void
    {
        $source = ContactIdentity::create([
            'team_id' => $this->team->id,
            'display_name' => 'Source',
        ]);
        $target = ContactIdentity::create([
            'team_id' => $this->team->id,
            'display_name' => 'Target',
        ]);

        ContactChannel::create([
            'team_id' => $this->team->id,
            'contact_identity_id' => $source->id,
            'channel' => 'telegram',
            'external_id' => 'tg-123',
        ]);

        $this->resolver->merge($source, $target);

        // Source is deleted
        $this->assertDatabaseMissing('contact_identities', ['id' => $source->id]);

        // Channel now belongs to target
        $channel = ContactChannel::withoutGlobalScopes()
            ->where('channel', 'telegram')
            ->where('external_id', 'tg-123')
            ->first();
        $this->assertNotNull($channel);
        $this->assertEquals($target->id, $channel->contact_identity_id);
    }

    public function test_merge_throws_for_cross_team_contacts(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        $source = ContactIdentity::create(['team_id' => $this->team->id, 'display_name' => 'S']);
        $target = ContactIdentity::create(['team_id' => $otherTeam->id, 'display_name' => 'T']);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->merge($source, $target);
    }

    public function test_different_channels_for_same_person_use_same_identity(): void
    {
        // First channel
        $first = $this->resolver->resolveOrCreate(
            teamId: $this->team->id,
            channel: 'telegram',
            externalId: 'tg-user-777',
            hints: ['phone' => '+19995550000'],
        );

        // Second channel with same phone
        $second = $this->resolver->resolveOrCreate(
            teamId: $this->team->id,
            channel: 'whatsapp',
            externalId: 'wa-user-777',
            hints: ['phone' => '19995550000'],
        );

        $this->assertEquals($first->id, $second->id);

        // Two channels, one identity
        $channelCount = ContactChannel::withoutGlobalScopes()
            ->where('contact_identity_id', $first->id)
            ->count();
        $this->assertEquals(2, $channelCount);
    }
}
