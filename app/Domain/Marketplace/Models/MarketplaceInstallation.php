<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $listing_id
 * @property string $team_id
 * @property string $installed_by
 * @property string $installed_version
 * @property string|null $installed_skill_id
 * @property string|null $installed_agent_id
 * @property string|null $installed_workflow_id
 * @property string|null $installed_email_theme_id
 * @property string|null $installed_email_template_id
 * @property array<string, mixed>|null $bundle_metadata
 * @property string $total_credits_spent
 * @property string $total_revenue_earned
 */
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
        'bundle_metadata',
        'total_credits_spent',
        'total_revenue_earned',
    ];

    protected function casts(): array
    {
        return [
            'bundle_metadata' => 'array',
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

    public function installedSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'installed_skill_id');
    }
}
