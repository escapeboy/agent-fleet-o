<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\MessageDirection;
use App\Domain\AgentChatProtocol\Enums\MessageStatus;
use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentChatMessage extends Model
{
    use BelongsToTeam;
    use HasFactory;
    use HasUuids;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TeamScope);
    }

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'message_type' => MessageType::class,
            'status' => MessageStatus::class,
            'payload' => 'array',
            'latency_ms' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentChatSession::class, 'session_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function externalAgent(): BelongsTo
    {
        return $this->belongsTo(ExternalAgent::class);
    }
}
