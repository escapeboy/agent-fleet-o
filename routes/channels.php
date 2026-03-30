<?php

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Bridge daemon private channel — one channel per team.
// The daemon authenticates using the team's Sanctum bearer token.
Broadcast::channel('daemon.{teamId}', function ($user, string $teamId) {
    if ($user->current_team_id !== $teamId) {
        return false;
    }

    return ['id' => $user->id, 'team_id' => $teamId];
});

// Experiment real-time updates (WorkflowNodeUpdated, step streaming).
// Only team members who own the experiment may subscribe.
Broadcast::channel('experiment.{experimentId}', function ($user, string $experimentId) {
    $experiment = Experiment::withoutGlobalScopes()
        ->where('id', $experimentId)
        ->first();

    if (! $experiment) {
        return false;
    }

    if ($experiment->team_id !== $user->current_team_id) {
        return false;
    }

    return ['id' => $user->id, 'team_id' => $experiment->team_id];
});
