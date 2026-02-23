<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Database\Factories\Domain\Marketplace\MarketplaceListingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceListing extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return MarketplaceListingFactory::new();
    }

    protected $fillable = [
        'team_id',
        'published_by',
        'type',
        'listable_id',
        'name',
        'slug',
        'description',
        'readme',
        'category',
        'tags',
        'status',
        'visibility',
        'version',
        'configuration_snapshot',
        'install_count',
        'avg_rating',
        'review_count',
        'run_count',
        'success_count',
        'avg_cost_credits',
        'avg_duration_ms',
        'usage_trend',
        'price_per_run_credits',
        'monetization_enabled',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceStatus::class,
            'visibility' => ListingVisibility::class,
            'tags' => 'array',
            'configuration_snapshot' => 'array',
            'usage_trend' => 'array',
            'install_count' => 'integer',
            'run_count' => 'integer',
            'success_count' => 'integer',
            'avg_rating' => 'decimal:2',
            'avg_cost_credits' => 'decimal:4',
            'avg_duration_ms' => 'decimal:2',
            'price_per_run_credits' => 'decimal:4',
            'monetization_enabled' => 'boolean',
            'review_count' => 'integer',
        ];
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(MarketplaceUsageRecord::class, 'listing_id');
    }

    public function isPaid(): bool
    {
        return $this->monetization_enabled && $this->price_per_run_credits > 0;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(MarketplaceInstallation::class, 'listing_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MarketplaceReview::class, 'listing_id');
    }

    public function isPublished(): bool
    {
        return $this->status === MarketplaceStatus::Published
            && $this->visibility === ListingVisibility::Public;
    }
}
