<?php

namespace App\Domain\Shared\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Skip scoping in console UNLESS MCP server is active or running tests
        if (app()->runningInConsole() && ! app()->runningUnitTests() && ! app()->bound('mcp.active')) {
            return;
        }

        $user = auth()->user();

        if ($user && $user->current_team_id) {
            $teamId = $user->current_team_id;
            $table  = $model->getTable();

            // Wrap in closure so OR does not leak into other WHERE clauses
            $builder->where(function (Builder $query) use ($table, $teamId) {
                $query->where($table.'.team_id', $teamId)
                    ->orWhereNull($table.'.team_id');
            });
        } elseif ($user) {
            // Authenticated user with no team — return nothing to prevent cross-tenant leaks
            $builder->whereRaw('1=0');
        }
    }
}
