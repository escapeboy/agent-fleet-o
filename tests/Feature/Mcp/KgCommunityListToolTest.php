<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Signal\KgCommunityListTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class KgCommunityListToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_own_team_communities(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'KG List Team',
            'slug' => 'kg-list-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other KG Team',
            'slug' => 'kg-list-other',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        KgCommunity::create([
            'team_id' => $team->id,
            'label' => 'Mine',
            'summary' => 'A community of mine',
            'entity_ids' => [],
            'size' => 5,
            'top_entities' => [],
        ]);
        KgCommunity::create([
            'team_id' => $otherTeam->id,
            'label' => 'Theirs',
            'summary' => 'Should never appear',
            'entity_ids' => [],
            'size' => 9,
            'top_entities' => [],
        ]);

        app()->instance('mcp.team_id', $team->id);

        $response = (new KgCommunityListTool)->handle(new Request([]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError());
        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertCount(1, $payload['communities']);
        $this->assertSame('Mine', $payload['communities'][0]['label']);
    }
}
