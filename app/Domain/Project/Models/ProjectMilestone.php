<?php

namespace App\Domain\Project\Models;

use App\Domain\Project\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $title
 * @property string|null $description
 * @property array<string, mixed>|null $criteria
 * @property int $sort_order
 * @property MilestoneStatus|null $status
 * @property Carbon|null $completed_at
 * @property string|null $completed_by_run_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectMilestone extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'criteria',
        'sort_order',
        'status',
        'completed_at',
        'completed_by_run_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'criteria' => 'array',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function completedByRun(): BelongsTo
    {
        return $this->belongsTo(ProjectRun::class, 'completed_by_run_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === MilestoneStatus::Completed;
    }

    public function markComplete(ProjectRun $run): void
    {
        $this->update([
            'status' => MilestoneStatus::Completed,
            'completed_at' => now(),
            'completed_by_run_id' => $run->id,
        ]);
    }
}
