<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Resolves the Phoenix project name a trace should land in.
 *
 * Phoenix groups traces by project (the resource attribute
 * `openinference.project.name`). By default every trace shares one project
 * (`llmops.phoenix.project`, e.g. `fleetq`). When `project_per_team` is on,
 * each team gets its own project (`fleetq-<team-slug>`) so multi-team installs
 * can read one team's LLM activity without the others' noise.
 *
 * The team slug lookup is cached — this runs on the hot path of every exported
 * LLM call, and team slugs change rarely.
 */
class PhoenixProjectResolver
{
    public function __construct(private readonly Cache $cache) {}

    public function resolve(?string $teamId): string
    {
        $base = (string) config('llmops.phoenix.project', 'fleetq');

        if ($teamId === null || ! (bool) config('llmops.phoenix.project_per_team', false)) {
            return $base;
        }

        return $this->cache->remember(
            "phoenix:project:{$teamId}",
            3600,
            function () use ($base, $teamId): string {
                $slug = Team::withoutGlobalScopes()->find($teamId)?->slug;

                if (! is_string($slug) || trim($slug) === '') {
                    $slug = substr($teamId, 0, 8);
                }

                return $base.'-'.$slug;
            },
        );
    }
}
