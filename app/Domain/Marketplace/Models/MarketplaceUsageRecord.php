<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceUsageRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'listing_id',
        'installation_id',
        'team_id',
        'status',
        'cost_credits',
        'duration_ms',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_credits' => 'decimal:6',
            'executed_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(MarketplaceInstallation::class, 'installation_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
