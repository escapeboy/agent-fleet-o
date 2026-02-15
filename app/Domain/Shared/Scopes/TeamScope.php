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
            $builder->where($model->getTable().'.team_id', $user->current_team_id);
        }
    }
}
