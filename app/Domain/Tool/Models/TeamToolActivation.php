<?php

namespace App\Domain\Tool\Models;

use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamToolActivation extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'tool_id',
        'status',
        'credential_overrides',
        'config_overrides',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'credential_overrides' => 'encrypted:array',
            'config_overrides' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
