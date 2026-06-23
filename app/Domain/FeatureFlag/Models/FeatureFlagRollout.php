<?php

namespace App\Domain\FeatureFlag\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persisted percentage-rollout setting for a Tier-2 runtime feature flag.
 *
 * Not team-scoped: a rollout percentage is a platform-wide default applied by
 * the FeatureFlagService define() closure to teams that have no explicit
 * per-team override. Per-team overrides live in Pennant's `features` table.
 *
 * @property string $id
 * @property string $key
 * @property int $percentage
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FeatureFlagRollout extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'percentage',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
