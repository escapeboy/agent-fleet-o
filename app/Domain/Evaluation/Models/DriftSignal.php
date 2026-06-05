<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\Evaluation\Enums\DriftSignalType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A single computed drift signal observation (#4).
 */
class DriftSignal extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'signal_type',
        'value',
        'baseline',
        'breached',
        'window',
        'detected_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'signal_type' => DriftSignalType::class,
            'value' => 'float',
            'baseline' => 'float',
            'breached' => 'boolean',
            'detected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
