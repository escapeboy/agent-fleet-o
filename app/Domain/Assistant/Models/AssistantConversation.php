<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $user_id
 * @property string|null $title
 * @property string|null $context_type
 * @property string|null $context_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $last_message_at
 * @property Carbon|null $expired_at
 * @property array<string, mixed>|null $review
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssistantConversation extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'title',
        'context_type',
        'context_id',
        'metadata',
        'last_message_at',
        'expired_at',
        'review',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'review' => 'array',
            'last_message_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('expired_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
