<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReasoningBankEntry extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'goal_text',
        'tool_sequence',
        'outcome_summary',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'tool_sequence' => 'array',
            'embedding' => 'array',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
