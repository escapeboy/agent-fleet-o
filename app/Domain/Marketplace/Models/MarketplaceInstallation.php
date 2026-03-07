<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'installed_email_theme_id',
        'installed_email_template_id',
        'total_credits_spent',
        'total_revenue_earned',
    ];

    protected function casts(): array
    {
        return [
            'total_credits_spent' => 'decimal:6',
            'total_revenue_earned' => 'decimal:6',
        ];
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(MarketplaceUsageRecord::class, 'installation_id');
    }

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
