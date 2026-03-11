<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Enums\ChatbotStatus;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $team_id
 * @property string $agent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ChatbotType $type
 * @property ChatbotStatus $status
 * @property bool $agent_is_dedicated
 * @property array $config
 * @property array $widget_config
 * @property float $confidence_threshold
 * @property bool $human_escalation_enabled
 * @property string|null $welcome_message
 * @property string|null $fallback_message
 */
class Chatbot extends Model
{
    use BelongsToTeam, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'agent_id',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'agent_is_dedicated',
        'config',
        'widget_config',
        'confidence_threshold',
        'human_escalation_enabled',
        'welcome_message',
        'fallback_message',
    ];

    protected function casts(): array
    {
        return [
            'type' => ChatbotType::class,
            'status' => ChatbotStatus::class,
            'agent_is_dedicated' => 'boolean',
            'config' => 'array',
            'widget_config' => 'array',
            'confidence_threshold' => 'decimal:2',
            'human_escalation_enabled' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ChatbotToken::class);
    }

    public function activeTokens(): HasMany
    {
        return $this->hasMany(ChatbotToken::class)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ChatbotChannel::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ChatbotSession::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class);
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(ChatbotKnowledgeSource::class);
    }

    public function kbChunks(): HasMany
    {
        return $this->hasMany(ChatbotKbChunk::class);
    }

    public function activeChannels(): HasMany
    {
        return $this->hasMany(ChatbotChannel::class)->where('is_active', true);
    }

    public function isActive(): bool
    {
        return $this->status === ChatbotStatus::Active;
    }

    public function hasBudgetRemaining(): bool
    {
        return $this->agent?->hasBudgetRemaining() ?? true;
    }
}
