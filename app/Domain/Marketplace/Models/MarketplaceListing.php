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
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $published_by
 * @property string $type
 * @property string|null $listable_id
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property string|null $readme
 * @property string|null $category
 * @property array<int, string>|null $tags
 * @property MarketplaceStatus $status
 * @property ListingVisibility $visibility
 * @property bool $is_official
 * @property string $version
 * @property array<string, mixed>|null $configuration_snapshot
 * @property array<string, mixed>|null $execution_profile
 * @property array<string, mixed>|null $demo_surface
 * @property array<string, mixed>|null $risk_scan
 * @property int $install_count
 * @property int $run_count
 * @property int $success_count
 * @property string|null $avg_cost_credits
 * @property string|null $avg_duration_ms
 * @property array<string, mixed>|null $usage_trend
 * @property string $price_per_run_credits
 * @property bool $monetization_enabled
 * @property string $avg_rating
 * @property int $review_count
 * @property float $community_quality_score
 * @property float $install_success_rate
 * @property float $community_reliability_rate
 * @property int $effective_run_count
 * @property Carbon|null $quality_computed_at
 */
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
        'is_official',
        'execution_profile',
        'demo_surface',
        'community_quality_score',
        'install_success_rate',
        'community_reliability_rate',
        'effective_run_count',
        'quality_computed_at',
        'risk_scan',
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
            'is_official' => 'boolean',
            'review_count' => 'integer',
            'execution_profile' => 'array',
            'demo_surface' => 'array',
            'community_quality_score' => 'float',
            'install_success_rate' => 'float',
            'community_reliability_rate' => 'float',
            'effective_run_count' => 'integer',
            'quality_computed_at' => 'datetime',
            'risk_scan' => 'array',
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
