<?php

namespace App\Domain\Simulation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Simulation\Enums\SimulationStatus;
use Database\Factories\Domain\Simulation\SimulationRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $team_id
 * @property string $suite_id
 * @property SimulationStatus $status
 * @property array<string, mixed>|null $aggregate
 * @property int $cost_credits
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property string|null $error
 * @property string|null $created_by
 * @property-read SimulationSuite $suite
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SimulationTranscript> $transcripts
 */
class SimulationRun extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected static function newFactory(): SimulationRunFactory
    {
        return SimulationRunFactory::new();
    }

    protected $fillable = [
        'team_id',
        'suite_id',
        'status',
        'aggregate',
        'cost_credits',
        'started_at',
        'finished_at',
        'error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SimulationStatus::class,
            'aggregate' => 'array',
            'cost_credits' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(SimulationSuite::class, 'suite_id');
    }

    public function transcripts(): HasMany
    {
        return $this->hasMany(SimulationTranscript::class, 'run_id');
    }
}
