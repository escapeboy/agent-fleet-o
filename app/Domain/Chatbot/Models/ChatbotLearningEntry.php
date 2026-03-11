<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Chatbot\Enums\LearningEntryStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotLearningEntry extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'chatbot_learning_entries';

    protected $fillable = [
        'chatbot_id',
        'session_id',
        'message_id',
        'team_id',
        'user_message',
        'original_response',
        'corrected_response',
        'reason_code',
        'operator_notes',
        'model_config',
        'status',
        'exported_at',
    ];

    protected $casts = [
        'model_config' => 'array',
        'status' => LearningEntryStatus::class,
        'exported_at' => 'datetime',
    ];

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatbotSession::class, 'session_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatbotMessage::class, 'message_id');
    }
}
