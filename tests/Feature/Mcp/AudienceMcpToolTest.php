<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Audience\Models\Audience;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Audience\AudienceCreateTool;
use App\Mcp\Tools\Audience\AudienceListTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class AudienceMcpToolTest extends TestCase
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

    public function test_create_then_list_returns_the_new_audience(): void
    {
        (new AudienceCreateTool)->handle(new Request([
            'name' => 'Beta Testers',
            'topic' => 'product_updates',
        ]));

        $listed = $this->decode((new AudienceListTool)->handle(new Request([])));

        $this->assertSame(1, $listed['count']);
        $this->assertSame('Beta Testers', $listed['audiences'][0]['name']);
        $this->assertSame('product_updates', $listed['audiences'][0]['topic']);
    }

    public function test_list_does_not_leak_other_teams_audiences(): void
    {
        $otherTeam = Team::factory()->create();
        Audience::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Other Team List']);
        Audience::factory()->create(['team_id' => $this->team->id, 'name' => 'My List']);

        $listed = $this->decode((new AudienceListTool)->handle(new Request([])));

        $this->assertSame(1, $listed['count']);
        $this->assertSame('My List', $listed['audiences'][0]['name']);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
