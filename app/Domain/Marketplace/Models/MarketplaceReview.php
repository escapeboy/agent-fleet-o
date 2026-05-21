<?php

namespace App\Domain\Marketplace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $listing_id
 * @property string $user_id
 * @property string $team_id
 * @property int $rating
 * @property string|null $comment
 */
class MarketplaceReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'listing_id',
        'user_id',
        'team_id',
        'rating',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
