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
use App\Domain\Workflow\Models\Workflow;
use App\Models\Artifact;
use App\Models\User;
use Database\Factories\Domain\Experiment\ExperimentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string|null $user_id
 * @property string|null $workflow_id
 * @property int|null $workflow_version
 * @property string $title
 * @property string|null $thesis
 * @property ExperimentTrack|null $track
 * @property ExperimentStatus $status
 * @property string|null $paused_from_status
 * @property array|null $constraints
 * @property array|null $success_criteria
 * @property int|null $budget_cap_credits
 * @property int $budget_spent_credits
 * @property int|null $max_iterations
 * @property int $current_iteration
 * @property int|null $max_outbound_count
 * @property int $outbound_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $killed_at
 * @property string|null $parent_experiment_id
 * @property int $nesting_depth
 * @property array|null $orchestration_config
 */
class Experiment extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'parent_experiment_id',
        'nesting_depth',
        'workflow_id',
        'workflow_version',
        'title',
        'thesis',
        'track',
        'status',
        'paused_from_status',
        'constraints',
        'orchestration_config',
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
            'orchestration_config' => 'array',
            'success_criteria' => 'array',
            'nesting_depth' => 'integer',
            'budget_cap_credits' => 'integer',
            'budget_spent_credits' => 'integer',
            'max_iterations' => 'integer',
            'current_iteration' => 'integer',
            'max_outbound_count' => 'integer',
            'outbound_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'workflow_version' => 'integer',
            'killed_at' => 'datetime',
        ];
    }

    protected static function newFactory()
    {
        return ExperimentFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_experiment_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_experiment_id');
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

    public function tasks(): HasMany
    {
        return $this->hasMany(ExperimentTask::class)->orderBy('sort_order');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function hasWorkflow(): bool
    {
        return $this->workflow_id !== null;
    }

    public function isYoloMode(): bool
    {
        return ($this->constraints['execution_mode'] ?? null) === 'yolo';
    }

    public function isSubExperiment(): bool
    {
        return $this->parent_experiment_id !== null;
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }
}
