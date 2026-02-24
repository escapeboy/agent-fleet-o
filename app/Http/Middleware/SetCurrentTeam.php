<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

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
