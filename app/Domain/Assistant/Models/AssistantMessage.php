<?php

namespace App\Domain\Assistant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $role
 * @property string|null $content
 * @property array<int, array<string, mixed>>|null $tool_calls
 * @property array<int, array<string, mixed>>|null $tool_results
 * @property array<string, mixed>|null $token_usage
 * @property array<string, mixed>|null $metadata
 * @property array<int, array<string, mixed>>|null $ui_artifacts
 * @property Carbon $created_at
 */
class AssistantMessage extends Model
{
    use HasUuids, MassPrunable;

    public function prunable(): Builder
    {
        // Retain 90 days of conversation history
        return static::where('created_at', '<', now()->subDays(90));
    }

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'token_usage',
        'metadata',
        'ui_artifacts',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'token_usage' => 'array',
            'metadata' => 'array',
            'ui_artifacts' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function uiArtifacts(): HasMany
    {
        return $this->hasMany(AssistantUiArtifact::class, 'assistant_message_id');
    }
}
