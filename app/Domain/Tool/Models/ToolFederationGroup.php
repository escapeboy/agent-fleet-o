<?php

namespace App\Domain\Tool\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ToolFederationGroup extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'tool_ids',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tool_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
