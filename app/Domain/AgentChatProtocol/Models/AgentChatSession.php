<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentChatSession extends Model
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
            'metadata' => 'array',
            'last_activity_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function externalAgent(): BelongsTo
    {
        return $this->belongsTo(ExternalAgent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentChatMessage::class, 'session_id')->orderBy('created_at');
    }

    public function touch($attribute = null): bool
    {
        $this->last_activity_at = now();
        $this->message_count = (int) $this->message_count + 1;

        return $this->save();
    }
}
