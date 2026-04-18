<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\RouteMap;

class RegisterRouteMapAction
{
    /**
     * Store or replace the route map for a project.
     *
     * @param  array<int, array<string, mixed>>  $routes
     */
    public function execute(string $teamId, string $project, string $release, array $routes): RouteMap
    {
        return RouteMap::updateOrCreate(
            ['team_id' => $teamId, 'project' => $project],
            ['release' => $release, 'routes' => $routes],
        );
    }
}
