<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Invalidate web sessions for removed team members.
        // RevokeTeamMemberAccess listener writes "team_revoked:{userId}:{teamId}"
        // to the cache Redis connection when a member is detached from a team.
        if ($user && $user->current_team_id) {
            $revocationKey = 'team_revoked:'.$user->id.':'.$user->current_team_id;

            try {
                if (Redis::connection('cache')->exists($revocationKey)) {
                    Redis::connection('cache')->del($revocationKey);
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->with(
                        'status',
                        'Your access to this workspace has been revoked.',
                    );
                }
            } catch (\Throwable) {
                // Redis unavailable — fail open (don't block the request)
            }
        }

        if ($user && ! $user->current_team_id) {
            // Auto-assign first team if no current team set
            $firstTeam = $user->teams()->first();

            if ($firstTeam) {
                $user->update(['current_team_id' => $firstTeam->id]);
                $user->load('currentTeam');
            } elseif (! config('cloud.mode', false)) {
                // Self-hosted: no team exists at all — installer was not run
                abort(503, 'No workspace configured. Run `php artisan app:install` to set up the application.');
            }
            // Cloud: no team yet — EnsureTeamExists middleware will redirect to onboarding
        }

        return $next($request);
    }
}
