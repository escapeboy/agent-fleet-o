<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Domain\Shared\Models\Team;
use App\Livewire\KnowledgeGraph\KgCommunitiesPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class KgCommunitiesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Communities Test Team',
            'slug' => 'communities-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeCommunity(string $teamId, string $label, int $size = 3): KgCommunity
    {
        return KgCommunity::create([
            'team_id' => $teamId,
            'label' => $label,
            'summary' => 'A cluster of related entities.',
            'entity_ids' => ['e1', 'e2', 'e3'],
            'size' => $size,
            'top_entities' => [
                ['id' => 'e1', 'name' => 'Alpha', 'type' => 'topic', 'mentions' => 5],
            ],
        ]);
    }

    public function test_lists_only_current_team_communities(): void
    {
        $this->makeCommunity($this->team->id, 'My Team Cluster Unique-A1B2C3');

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $this->makeCommunity($otherTeam->id, 'Foreign Cluster Should-Not-Appear-Z9Y8X7');

        Livewire::test(KgCommunitiesPage::class)
            ->assertSee('My Team Cluster Unique-A1B2C3')
            ->assertDontSee('Foreign Cluster Should-Not-Appear-Z9Y8X7');
    }

    public function test_search_filters_by_label(): void
    {
        $this->makeCommunity($this->team->id, 'Billing Cluster Findme-7K');
        $this->makeCommunity($this->team->id, 'Marketing Cluster Other-2Q');

        Livewire::test(KgCommunitiesPage::class)
            ->set('search', 'Findme-7K')
            ->assertSee('Billing Cluster Findme-7K')
            ->assertDontSee('Marketing Cluster Other-2Q');
    }

    public function test_rebuild_forbidden_without_edit_content(): void
    {
        Gate::define('edit-content', fn () => false);

        Livewire::test(KgCommunitiesPage::class)
            ->call('rebuild')
            ->assertForbidden();
    }
}
