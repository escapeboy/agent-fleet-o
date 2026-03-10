<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformTeamSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'platform@fleetq.net'],
            [
                'name' => 'FleetQ Platform',
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => now(),
            ]
        );

        $team = Team::withoutGlobalScopes()->firstOrCreate(
            ['slug' => 'fleetq-platform'],
            [
                'name' => 'FleetQ Platform',
                'owner_id' => $user->id,
                'is_platform' => true,
            ]
        );

        // Ensure is_platform is set on existing teams (idempotent)
        if (! $team->is_platform) {
            $team->update(['is_platform' => true]);
        }

        // Attach user to team if not already a member
        if (! $team->users()->where('user_id', $user->id)->exists()) {
            $team->users()->attach($user->id, ['role' => 'owner']);
        }

        $this->command?->info("Platform team ready: {$team->id}");

        config(['_platform_team_id' => $team->id]);
        config(['_platform_user_id' => $user->id]);
    }
}
