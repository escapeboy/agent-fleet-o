<?php

namespace App\Domain\Website\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Models\User;
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
        'user_id',
        'name',
        'slug',
        'status',
        'settings',
        'custom_domain',
        'content_version',
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

    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(WebsiteAsset::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(WebsiteDeployment::class);
    }
}
