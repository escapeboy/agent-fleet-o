<?php

namespace Tests\Feature\Livewire;

use App\Domain\Audience\Actions\AddAudienceMember;
use App\Domain\Audience\Models\Audience;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use App\Livewire\Audiences\AudienceDetailPage;
use App\Livewire\Audiences\AudienceListPage;
use App\Livewire\Broadcast\BroadcastDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AudienceBroadcastUiTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $user->id]);
        $this->team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
    }

    public function test_audience_list_page_creates_an_audience(): void
    {
        Livewire::test(AudienceListPage::class)
            ->set('name', 'Newsletter')
            ->set('topic', 'newsletter')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(1, Audience::query()->where('name', 'Newsletter')->count());
    }

    public function test_audience_detail_adds_a_member(): void
    {
        $audience = Audience::factory()->create(['team_id' => $this->team->id]);

        Livewire::test(AudienceDetailPage::class, ['audience' => $audience])
            ->set('memberEmail', 'lead@example.com')
            ->call('addMember')
            ->assertHasNoErrors();

        $this->assertSame(1, AudienceMember::withoutGlobalScopes()
            ->where('audience_id', $audience->id)->count());
    }

    public function test_broadcast_detail_requests_approval(): void
    {
        Queue::fake();
        $audience = Audience::factory()->create(['team_id' => $this->team->id]);
        app(AddAudienceMember::class)->execute(
            $audience,
            ContactIdentity::factory()->create(['team_id' => $this->team->id]),
        );
        $broadcast = Broadcast::factory()->create([
            'team_id' => $this->team->id,
            'audience_id' => $audience->id,
            'status' => BroadcastStatus::Draft,
        ]);

        Livewire::test(BroadcastDetailPage::class, ['broadcast' => $broadcast])
            ->call('requestApproval');

        $this->assertSame(BroadcastStatus::PendingApproval, $broadcast->fresh()->status);
    }
}
