<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Enums\ErrorModeStatus;
use App\Domain\ErrorMode\Models\ErrorMode;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\ErrorMode\ErrorModeAssignLeverTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ErrorModeAssignLeverToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'EM Assign',
            'slug' => 'em-assign-'.uniqid(),
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

    private function makeMode(string $teamId): ErrorMode
    {
        return ErrorMode::create([
            'team_id' => $teamId,
            'slug' => 'mode-'.uniqid(),
            'name' => 'Missed retrieval',
            'lever' => ErrorModeLever::Unassigned,
            'status' => ErrorModeStatus::Open,
            'occurrence_count' => 3,
            'last_seen_at' => now(),
            'example_trace_ids' => [],
            'metadata' => [],
        ]);
    }

    public function test_assigns_lever_for_own_team_mode(): void
    {
        $mode = $this->makeMode($this->team->id);

        $tool = new ErrorModeAssignLeverTool;
        $payload = $this->decode($tool->handle(new Request([
            'error_mode_id' => $mode->id,
            'lever' => 'retrieval',
        ])));

        $this->assertSame($mode->id, $payload['id']);
        $this->assertSame(ErrorModeLever::Retrieval, $mode->fresh()->lever);
    }

    public function test_cannot_assign_another_teams_mode(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-'.uniqid(),
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $foreignMode = $this->makeMode($otherTeam->id);

        $tool = new ErrorModeAssignLeverTool;
        $payload = $this->decode($tool->handle(new Request([
            'error_mode_id' => $foreignMode->id,
            'lever' => 'retrieval',
        ])));

        $this->assertSame('Error mode not found.', $payload['error']);
        // Foreign mode is untouched.
        $this->assertSame(ErrorModeLever::Unassigned, $foreignMode->fresh()->lever);
    }
}
