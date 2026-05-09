<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InboxTriageResult extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'source_kind',
        'source_id',
        'llm_score',
        'llm_recommendation',
        'llm_reason',
        'user_action',
        'user_acted_at',
    ];

    protected function casts(): array
    {
        return [
            'llm_score' => 'float',
            'user_acted_at' => 'datetime',
        ];
    }
}
