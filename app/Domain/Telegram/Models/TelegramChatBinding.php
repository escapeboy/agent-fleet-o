<?php

namespace App\Domain\Telegram\Models;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $chat_id
 * @property string|null $user_id
 * @property string|null $conversation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read AssistantConversation|null $conversation
 */
class TelegramChatBinding extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'chat_id',
        'user_id',
        'conversation_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }
}
