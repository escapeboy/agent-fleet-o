<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Metrics\Models\Metric;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Signal\Models\Signal;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Experiment extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'title',
        'thesis',
        'track',
        'status',
        'paused_from_status',
        'constraints',
        'success_criteria',
        'budget_cap_credits',
        'budget_spent_credits',
        'max_iterations',
        'current_iteration',
        'max_outbound_count',
        'outbound_count',
        'started_at',
        'completed_at',
        'killed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExperimentStatus::class,
            'track' => ExperimentTrack::class,
            'constraints' => 'array',
            'success_criteria' => 'array',
            'budget_cap_credits' => 'integer',
            'budget_spent_credits' => 'integer',
            'max_iterations' => 'integer',
            'current_iteration' => 'integer',
            'max_outbound_count' => 'integer',
            'outbound_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'killed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ExperimentStage::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    public function outboundProposals(): HasMany
    {
        return $this->hasMany(OutboundProposal::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    public function creditLedgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    public function stateTransitions(): HasMany
    {
        return $this->hasMany(ExperimentStateTransition::class);
    }

    public function playbookSteps(): HasMany
    {
        return $this->hasMany(PlaybookStep::class)->orderBy('order');
    }
}
