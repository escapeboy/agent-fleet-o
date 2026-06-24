<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Memory\Actions\DistillTeamEventsAction;
use App\Domain\Memory\Jobs\DistillTeamEventsJob;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\TeamAiAccessChecker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-flight gate (#875/#848): this autonomous per-team job must not attempt an
 * LLM call for a BYOK team with no usable AI path, which would otherwise fail
 * mid-run and flood Sentry. Community edition reports canUseAi() = true, so the
 * gate is exercised with a mocked checker (the cloud BYOK case).
 */
class DistillTeamEventsJobGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    public function test_skips_distillation_when_team_cannot_use_ai(): void
    {
        $team = $this->makeTeam();

        $action = $this->createMock(DistillTeamEventsAction::class);
        $action->expects($this->never())->method('execute');

        $checker = $this->createMock(TeamAiAccessChecker::class);
        $checker->method('canUseAi')->willReturn(false);

        (new DistillTeamEventsJob($team->id))->handle($action, $checker);
    }

    public function test_runs_distillation_when_team_can_use_ai(): void
    {
        $team = $this->makeTeam();

        $action = $this->createMock(DistillTeamEventsAction::class);
        $action->expects($this->once())->method('execute')->willReturn([]);

        $checker = $this->createMock(TeamAiAccessChecker::class);
        $checker->method('canUseAi')->willReturn(true);

        (new DistillTeamEventsJob($team->id))->handle($action, $checker);
    }

    public function test_skips_when_team_missing(): void
    {
        $action = $this->createMock(DistillTeamEventsAction::class);
        $action->expects($this->never())->method('execute');

        $checker = $this->createMock(TeamAiAccessChecker::class);

        (new DistillTeamEventsJob('00000000-0000-0000-0000-000000000000'))
            ->handle($action, $checker);
    }
}
