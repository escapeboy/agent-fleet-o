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
            }
        }

        return $next($request);
    }
}
