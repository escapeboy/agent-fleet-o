<?php

namespace App\Domain\Agent\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file an agent produced inside its execution sandbox.
 * Kanwas-inspired sprint — sandbox observability (one-directional MVP).
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $experiment_id
 * @property string|null $agent_id
 * @property string|null $sandbox_id
 * @property string $path
 * @property string $operation
 * @property int|null $size_bytes
 * @property Carbon $captured_at
 */
class SandboxFileActivity extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'agent_id',
        'sandbox_id',
        'path',
        'operation',
        'size_bytes',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
