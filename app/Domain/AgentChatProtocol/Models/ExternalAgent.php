<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Models;

use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Scopes\TeamScope;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExternalAgent extends Model
{
    use BelongsToTeam;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TeamScope);
    }

    protected function casts(): array
    {
        return [
            'status' => ExternalAgentStatus::class,
            'adapter_kind' => AdapterKind::class,
            'manifest_cached' => 'array',
            'capabilities' => 'array',
            'metadata' => 'array',
            'manifest_fetched_at' => 'datetime',
            'last_call_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentChatMessage::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AgentChatSession::class);
    }
}
