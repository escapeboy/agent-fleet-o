<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Signal\Models\Signal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactIdentity extends Model
{
    use BelongsToTeam, HasUuids;

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
