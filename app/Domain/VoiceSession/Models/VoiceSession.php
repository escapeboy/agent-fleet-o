<?php

namespace App\Domain\VoiceSession\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\VoiceSession\Enums\VoiceSessionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $agent_id
 * @property string|null $approval_request_id
 * @property string $created_by
 * @property string $room_name
 * @property VoiceSessionStatus $status
 * @property array $transcript Each turn: {role, content, timestamp}
 * @property array $settings stt_provider, tts_provider, voice_id, max_budget_credits, etc.
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class VoiceSession extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'approval_request_id',
        'created_by',
        'room_name',
        'status',
        'transcript',
        'settings',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VoiceSessionStatus::class,
            'transcript' => 'array',
            'settings' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whether the session can still accept participants. */
    public function isOpen(): bool
    {
        return in_array($this->status, [VoiceSessionStatus::Pending, VoiceSessionStatus::Active], true);
    }
}
