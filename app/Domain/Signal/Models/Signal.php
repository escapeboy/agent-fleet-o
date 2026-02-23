<?php

namespace App\Domain\Signal\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use Database\Factories\Domain\Signal\SignalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Signal extends Model implements HasMedia
{
    use BelongsToTeam, HasFactory, HasUuids, InteractsWithMedia;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'source_type',
        'source_identifier',
        'payload',
        'score',
        'scoring_details',
        'content_hash',
        'tags',
        'received_at',
        'scored_at',
        'duplicate_count',
        'last_received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scoring_details' => 'array',
            'tags' => 'array',
            'score' => 'float',
            'received_at' => 'datetime',
            'scored_at' => 'datetime',
            'duplicate_count' => 'integer',
            'last_received_at' => 'datetime',
        ];
    }

    protected static function newFactory()
    {
        return SignalFactory::new();
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entity_signal')
            ->withPivot(['context', 'confidence'])
            ->withTimestamps();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }
}
