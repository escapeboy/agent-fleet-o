<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Signal\Models\Signal;
use Database\Factories\Domain\Shared\ContactIdentityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string|null $display_name
 * @property string|null $email
 * @property string|null $phone
 * @property array<string, mixed>|null $metadata
 * @property int|null $risk_score
 * @property array<string, mixed>|null $risk_flags
 * @property Carbon|null $risk_evaluated_at
 * @property float|null $health_score
 * @property float|null $health_recency_score
 * @property float|null $health_frequency_score
 * @property float|null $health_sentiment_score
 * @property Carbon|null $health_scored_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContactIdentity extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected static function newFactory(): ContactIdentityFactory
    {
        return ContactIdentityFactory::new();
    }

    protected $fillable = [
        'team_id',
        'display_name',
        'email',
        'phone',
        'metadata',
        'risk_score',
        'risk_flags',
        'risk_evaluated_at',
        'health_score',
        'health_recency_score',
        'health_frequency_score',
        'health_sentiment_score',
        'health_scored_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'risk_flags' => 'array',
            'risk_score' => 'integer',
            'risk_evaluated_at' => 'datetime',
            'health_score' => 'float',
            'health_recency_score' => 'float',
            'health_frequency_score' => 'float',
            'health_sentiment_score' => 'float',
            'health_scored_at' => 'datetime',
        ];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ContactChannel::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }
}
