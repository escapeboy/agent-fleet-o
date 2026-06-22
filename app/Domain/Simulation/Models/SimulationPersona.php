<?php

namespace App\Domain\Simulation\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Simulation\SimulationPersonaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $team_id
 * @property string $suite_id
 * @property string $name
 * @property string|null $profile
 * @property string|null $goal
 * @property array<int, string>|null $adversarial_tags
 * @property string|null $seed_message
 */
class SimulationPersona extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected static function newFactory(): SimulationPersonaFactory
    {
        return SimulationPersonaFactory::new();
    }

    protected $fillable = [
        'team_id',
        'suite_id',
        'name',
        'profile',
        'goal',
        'adversarial_tags',
        'seed_message',
    ];

    protected function casts(): array
    {
        return [
            'adversarial_tags' => 'array',
        ];
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(SimulationSuite::class, 'suite_id');
    }
}
