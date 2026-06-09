<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Enums\ErrorModeStatus;
use App\Domain\ErrorMode\Models\ErrorMode;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\ErrorMode\ErrorModeListTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ErrorModeListToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'EM List',
            'slug' => 'em-list-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    private function makeMode(string $teamId, string $name, ErrorModeLever $lever): ErrorMode
    {
        return ErrorMode::create([
            'team_id' => $teamId,
            'slug' => str($name)->slug().'-'.uniqid(),
            'name' => $name,
            'lever' => $lever,
            'status' => ErrorModeStatus::Open,
            'occurrence_count' => 1,
            'last_seen_at' => now(),
            'example_trace_ids' => [],
            'metadata' => [],
        ]);
    }

    public function test_lists_only_own_team_modes(): void
    {
        $mine = $this->makeMode($this->team->id, 'Hallucinated citation', ErrorModeLever::Prompt);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-'.uniqid(),
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $this->makeMode($otherTeam->id, 'Cross tenant mode', ErrorModeLever::Retrieval);

        $tool = new ErrorModeListTool;
        $payload = $this->decode($tool->handle(new Request([])));

        $ids = array_column($payload['error_modes'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, $payload['error_modes']);
    }

    public function test_filters_by_lever(): void
    {
        $this->makeMode($this->team->id, 'Bad retrieval', ErrorModeLever::Retrieval);
        $this->makeMode($this->team->id, 'Bad prompt', ErrorModeLever::Prompt);

        $tool = new ErrorModeListTool;
        $payload = $this->decode($tool->handle(new Request(['lever' => 'retrieval'])));

        $this->assertCount(1, $payload['error_modes']);
        $this->assertSame('retrieval', $payload['error_modes'][0]['lever']);
    }
}
