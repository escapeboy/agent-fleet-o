<?php

namespace App\Domain\Website\Models;

use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Website\Enums\WebsiteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Website extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'status',
        'custom_domain',
        'settings',
        'crew_execution_id',
        'managing_crew_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebsiteStatus::class,
            'settings' => 'array',
        ];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class)->orderBy('sort_order');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(WebsiteAsset::class);
    }

    public function publishedPages(): HasMany
    {
        return $this->pages()->where('status', 'published');
    }

    public function crewExecution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class);
    }

    public function managingCrew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'managing_crew_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isGenerating(): bool
    {
        return $this->status === WebsiteStatus::Generating;
    }

    public function isPublished(): bool
    {
        return $this->status === WebsiteStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->status === WebsiteStatus::Draft;
    }
}
