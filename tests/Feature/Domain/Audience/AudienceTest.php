<?php

namespace Tests\Feature\Domain\Audience;

use App\Domain\Audience\Actions\AddAudienceMember;
use App\Domain\Audience\Actions\CreateAudience;
use App\Domain\Audience\Actions\UnsubscribeContact;
use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\Audience;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudienceTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    public function test_create_audience_generates_a_unique_slug_per_team(): void
    {
        $action = app(CreateAudience::class);

        $first = $action->execute($this->team->id, 'Weekly Digest');
        $second = $action->execute($this->team->id, 'Weekly Digest');

        $this->assertSame('weekly-digest', $first->slug);
        $this->assertSame('weekly-digest-2', $second->slug);
    }

    public function test_add_member_is_idempotent_and_resubscribes_after_unsubscribe(): void
    {
        $audience = Audience::factory()->create(['team_id' => $this->team->id]);
        $contact = ContactIdentity::factory()->create(['team_id' => $this->team->id]);
        $add = app(AddAudienceMember::class);

        $add->execute($audience, $contact);
        $add->execute($audience, $contact);

        $this->assertSame(1, AudienceMember::withoutGlobalScopes()
            ->where('audience_id', $audience->id)
            ->count());

        app(UnsubscribeContact::class)->execute($this->team->id, $contact);
        $resubscribed = $add->execute($audience, $contact);

        $this->assertSame(AudienceMemberStatus::Subscribed, $resubscribed->status);
        $this->assertNull($resubscribed->unsubscribed_at);
    }

    public function test_unsubscribe_with_no_audience_removes_contact_from_all_audiences(): void
    {
        $contact = ContactIdentity::factory()->create(['team_id' => $this->team->id]);
        $add = app(AddAudienceMember::class);

        foreach (['News', 'Promos'] as $name) {
            $add->execute(
                Audience::factory()->create(['team_id' => $this->team->id, 'name' => $name]),
                $contact,
            );
        }

        $count = app(UnsubscribeContact::class)->execute(
            teamId: $this->team->id,
            contact: $contact,
            reason: 'manual',
        );

        $this->assertSame(2, $count);
        $this->assertSame(0, AudienceMember::withoutGlobalScopes()
            ->where('contact_identity_id', $contact->id)
            ->where('status', AudienceMemberStatus::Subscribed->value)
            ->count());
    }
}
