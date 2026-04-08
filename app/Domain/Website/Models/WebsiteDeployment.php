<?php

namespace App\Domain\Website\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteDeployment extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'website_id',
        'team_id',
        'provider',
        'config',
        'status',
        'deployed_at',
        'build_log',
    ];

    protected $casts = [
        'provider' => DeploymentProvider::class,
        'status' => DeploymentStatus::class,
        'config' => 'array',
        'deployed_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
