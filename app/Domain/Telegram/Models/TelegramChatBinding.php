<?php

namespace App\Domain\Telegram\Models;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
