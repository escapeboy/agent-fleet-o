<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cached answer to a standalone question. Public-widget requests run without
 * an authenticated user, so TeamScope is inactive there: every query MUST filter
 * team_id AND chatbot_id explicitly (see ChatbotAnswerCache::scopedQuery()).
 *
 * @property string $id
 * @property string $team_id
 * @property string $chatbot_id
 * @property int $generation
 * @property string $prompt_hash
 * @property string $question
 * @property string $question_hash
 * @property string $answer
 * @property array|null $sources
 * @property string|null $confidence
 * @property int|null $generation_tokens
 * @property int|null $generation_cost_credits
 * @property int $hit_count
 */
class ChatbotAnswerCacheEntry extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'chatbot_answer_cache';

    protected $fillable = [
        'team_id',
        'chatbot_id',
        'generation',
        'prompt_hash',
        'question',
        'question_hash',
        'answer',
        'sources',
        'confidence',
        'generation_tokens',
        'generation_cost_credits',
        'hit_count',
        'last_hit_at',
        'expires_at',
    ];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'sources' => 'array',
            'hit_count' => 'integer',
            'generation_tokens' => 'integer',
            'generation_cost_credits' => 'integer',
            'last_hit_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }
}
