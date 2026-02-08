<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceListing extends Model
{
    use HasUuids;

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
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceStatus::class,
            'visibility' => ListingVisibility::class,
            'tags' => 'array',
            'configuration_snapshot' => 'array',
            'install_count' => 'integer',
            'avg_rating' => 'decimal:2',
            'review_count' => 'integer',
        ];
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
