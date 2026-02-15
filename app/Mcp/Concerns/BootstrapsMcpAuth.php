<?php

namespace App\Mcp\Concerns;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait BootstrapsMcpAuth
{
    protected function bootstrapMcpAuth(): void
    {
        if (auth()->check()) {
            return;
        }

        $team = Team::where('slug', 'default')->first() ?? Team::first();

        if (! $team) {
            throw new \RuntimeException('No team found. Run "php artisan app:install" first.');
        }

        $user = User::find($team->owner_id);

        if (! $user) {
            throw new \RuntimeException('Team owner not found. Run "php artisan app:install" first.');
        }

        Auth::login($user);

        if ($user->current_team_id !== $team->id) {
            $user->update(['current_team_id' => $team->id]);
        }

        app()->instance('mcp.active', true);
    }
}
