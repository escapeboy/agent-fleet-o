<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceInstallation extends Model
{
    use HasUuids;

    protected $fillable = [
        'listing_id',
        'team_id',
        'installed_by',
        'installed_version',
        'installed_skill_id',
        'installed_agent_id',
        'installed_workflow_id',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }
}
