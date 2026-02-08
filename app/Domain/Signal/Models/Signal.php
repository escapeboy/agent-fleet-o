<?php

namespace App\Domain\Signal\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signal extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'source_type',
        'source_identifier',
        'payload',
        'score',
        'scoring_details',
        'content_hash',
        'tags',
        'received_at',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scoring_details' => 'array',
            'tags' => 'array',
            'score' => 'float',
            'received_at' => 'datetime',
            'scored_at' => 'datetime',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
