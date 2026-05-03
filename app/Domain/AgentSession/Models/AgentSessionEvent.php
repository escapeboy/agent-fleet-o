<?php

namespace App\Domain\AgentSession\Models;

use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\Shared\Traits\BelongsToTeam;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only event in an AgentSession's log. Composite uniqueness on
 * (session_id, seq) lets writers be idempotent on retry.
 *
 * @property string $id
 * @property string $team_id
 * @property string $session_id
 * @property int $seq
 * @property AgentSessionEventKind $kind
 * @property array<string, mixed>|null $payload
 * @property Carbon $created_at
 */
class AgentSessionEvent extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'session_id',
        'seq',
        'kind',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AgentSessionEventKind::class,
            'payload' => 'array',
            'created_at' => 'datetime',
            'seq' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'session_id');
    }
}
