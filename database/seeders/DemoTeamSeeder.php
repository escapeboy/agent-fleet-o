<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal seed used by the standalone Glama / Docker build.
 *
 * Creates one user + one team named "default" so the MCP auth bootstrap
 * (`BootstrapsMcpAuth`) finds an owner on the very first stdio request.
 * Idempotent: re-running the seeder is a no-op once the team exists.
 */
class DemoTeamSeeder extends Seeder
{
    public function run(): void
    {
        if (Team::where('slug', 'default')->exists()) {
            return;
        }

        $user = User::firstOrCreate(
            ['email' => 'admin@fleetq.local'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $team = Team::create([
            'name' => 'Default',
            'slug' => 'default',
            'owner_id' => $user->id,
            'is_platform' => false,
            'settings' => [],
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();
    }
}
