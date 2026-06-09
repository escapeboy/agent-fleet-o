<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Outbound\OutboundProposalListTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class OutboundProposalListToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_it_lists_only_the_current_teams_proposals(): void
    {
        $mine = OutboundProposal::factory()->create(['team_id' => $this->team->id]);

        $otherTeam = Team::factory()->create();
        $theirs = OutboundProposal::factory()->create(['team_id' => $otherTeam->id]);

        $result = $this->decode((new OutboundProposalListTool)->handle(new Request([])));

        $ids = array_column($result['proposals'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertSame(1, $result['count']);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
