<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $agent_id
 * @property string|null $ai_run_id
 * @property string|null $agent_execution_id
 * @property string|null $crew_task_execution_id
 * @property string|null $experiment_stage_id
 * @property string|null $user_id
 * @property string $source
 * @property string $feedback_type
 * @property int|null $score
 * @property string|null $label
 * @property string|null $correction
 * @property string|null $comment
 * @property string|null $output_snapshot
 * @property string|null $input_snapshot
 * @property array $tags
 * @property Carbon|null $feedback_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AgentFeedback extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'ai_run_id',
        'agent_execution_id',
        'crew_task_execution_id',
        'experiment_stage_id',
        'user_id',
        'source',
        'feedback_type',
        'score',
        'label',
        'correction',
        'comment',
        'output_snapshot',
        'input_snapshot',
        'tags',
        'feedback_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'feedback_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    public function rating(): ?FeedbackRating
    {
        if ($this->score === null || $this->feedback_type !== 'binary') {
            return null;
        }

        return FeedbackRating::from($this->score);
    }

    public function isPositive(): bool
    {
        return $this->score === FeedbackRating::Positive->value;
    }

    public function isNegative(): bool
    {
        return $this->score === FeedbackRating::Negative->value;
    }
}
