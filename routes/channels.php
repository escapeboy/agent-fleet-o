<?php

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
