<?php

namespace App\Domain\Metrics\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'outbound_action_id',
        'type',
        'value',
        'source',
        'metadata',
        'occurred_at',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function outboundAction(): BelongsTo
    {
        return $this->belongsTo(OutboundAction::class);
    }
}
