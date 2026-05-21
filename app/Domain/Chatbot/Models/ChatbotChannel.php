<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Chatbot\Enums\ChannelType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $chatbot_id
 * @property ChannelType $channel_type
 * @property array<string, mixed> $config
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Chatbot|null $chatbot
 */
class ChatbotChannel extends Model
{
    use HasUuids;

    protected $fillable = [
        'chatbot_id',
        'channel_type',
        'config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }
}
