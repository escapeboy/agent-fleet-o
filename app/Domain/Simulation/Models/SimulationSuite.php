<?php

namespace App\Domain\Simulation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Simulation\Enums\SimulationTargetType;
use Database\Factories\Domain\Simulation\SimulationSuiteFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $team_id
 * @property string $name
 * @property SimulationTargetType $target_type
 * @property string $target_id
 * @property string|null $brief
 * @property array<int, string>|null $criteria
 * @property int $persona_count
 * @property int $max_turns
 * @property float $pass_threshold
 * @property string|null $created_by
 * @property-read Collection<int, SimulationPersona> $personas
 * @property-read Collection<int, SimulationRun> $runs
 */
class SimulationSuite extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected static function newFactory(): SimulationSuiteFactory
    {
        return SimulationSuiteFactory::new();
    }

    protected $fillable = [
        'team_id',
        'name',
        'target_type',
        'target_id',
        'brief',
        'criteria',
        'persona_count',
        'max_turns',
        'pass_threshold',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => SimulationTargetType::class,
            'criteria' => 'array',
            'persona_count' => 'integer',
            'max_turns' => 'integer',
            'pass_threshold' => 'float',
        ];
    }

    public function personas(): HasMany
    {
        return $this->hasMany(SimulationPersona::class, 'suite_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SimulationRun::class, 'suite_id');
    }
}
