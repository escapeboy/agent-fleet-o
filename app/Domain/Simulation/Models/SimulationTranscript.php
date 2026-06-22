<?php

namespace App\Domain\Simulation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Simulation\SimulationTranscriptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $team_id
 * @property string $run_id
 * @property string $persona_id
 * @property array<int, array{role: string, content: string}>|null $turns
 * @property array<string, mixed>|null $scores
 * @property string|null $verdict
 * @property int|null $failed_turn_index
 * @property-read SimulationPersona|null $persona
 */
class SimulationTranscript extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected static function newFactory(): SimulationTranscriptFactory
    {
        return SimulationTranscriptFactory::new();
    }

    protected $fillable = [
        'team_id',
        'run_id',
        'persona_id',
        'turns',
        'scores',
        'verdict',
        'failed_turn_index',
    ];

    protected function casts(): array
    {
        return [
            'turns' => 'array',
            'scores' => 'array',
            'failed_turn_index' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SimulationRun::class, 'run_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(SimulationPersona::class, 'persona_id');
    }
}
