<?php

namespace App\Domain\Credential\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialAccessLog extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'credential_id',
        'team_id',
        'agent_id',
        'tool_id',
        'resolved_for',
        'target_domain',
        'allowed',
    ];

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }
}
