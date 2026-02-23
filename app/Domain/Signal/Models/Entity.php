<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Entity extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'type',
        'name',
        'canonical_name',
        'metadata',
        'mention_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'mention_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function signals(): BelongsToMany
    {
        return $this->belongsToMany(Signal::class, 'entity_signal')
            ->withPivot(['context', 'confidence'])
            ->withTimestamps();
    }
}
