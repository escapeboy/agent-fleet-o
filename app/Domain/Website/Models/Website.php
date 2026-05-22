<?php

namespace App\Domain\Website\Models;

use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $user_id
 * @property string $name
 * @property string $slug
 * @property WebsiteStatus $status
 * @property array<string, mixed>|null $settings
 * @property string|null $custom_domain
 * @property int $content_version
 * @property string|null $managing_crew_id
 * @property string|null $crew_execution_id
 * @property Collection<int, WebsitePage> $pages
 */
class Website extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'user_id',
        'name',
        'slug',
        'status',
        'settings',
        'custom_domain',
        'content_version',
        'managing_crew_id',
        'crew_execution_id',
    ];

    protected $casts = [
        'status' => WebsiteStatus::class,
        'settings' => 'array',
        'content_version' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WebsitePage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class);
    }

    /** @return HasMany<WebsiteAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(WebsiteAsset::class);
    }

    /** @return HasMany<WebsiteDeployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(WebsiteDeployment::class);
    }

    public function managingCrew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'managing_crew_id');
    }

    public function crewExecution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class);
    }
}
