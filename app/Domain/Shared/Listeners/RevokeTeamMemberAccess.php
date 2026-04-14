<?php

namespace App\Domain\Shared\Listeners;

use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Events\TeamMemberRemoved;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeTeamMemberAccess
{
    public function __construct(
        private readonly PauseExperimentAction $pause,
    ) {}

    // Redis TTL for session revocation markers — 7 days covers any realistic session lifetime.
    private const REVOCATION_TTL_SECONDS = 604800;

    public function handle(TeamMemberRemoved $event): void
    {
        $this->revokeApiTokens($event);
        $this->markSessionRevoked($event);
        $this->pauseActiveExperiments($event);
    }

    /**
     * Write a Redis marker so SetCurrentTeam middleware can invalidate
     * active web sessions for this user+team combination on next request.
     */
    private function markSessionRevoked(TeamMemberRemoved $event): void
    {
        $key = 'team_revoked:'.$event->userId.':'.$event->team->id;
        Redis::connection('cache')->setex($key, self::REVOCATION_TTL_SECONDS, '1');
    }

    private function revokeApiTokens(TeamMemberRemoved $event): void
    {
        PersonalAccessToken::where('tokenable_type', User::class)
            ->where('tokenable_id', $event->userId)
            ->where('name', 'like', '%team:'.$event->team->id.'%')
            ->delete();
    }

    private function pauseActiveExperiments(TeamMemberRemoved $event): void
    {
        $activeStatuses = array_map(
            fn ($s) => $s->value,
            array_filter(
                ExperimentStatus::cases(),
                fn ($s) => ! in_array($s->value, ['completed', 'killed', 'discarded', 'expired', 'paused'], true),
            ),
        );

        if (empty($activeStatuses)) {
            return;
        }

        Experiment::withoutGlobalScopes()
            ->where('team_id', $event->team->id)
            ->where('user_id', $event->userId)
            ->whereIn('status', $activeStatuses)
            ->each(function (Experiment $experiment) {
                try {
                    $this->pause->execute($experiment, null, 'Member removed from team');
                } catch (\Throwable) {
                    // Best-effort — don't fail the whole removal
                }
            });
    }
}
