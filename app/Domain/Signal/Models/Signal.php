<?php

namespace App\Domain\Signal\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Signal\Enums\SignalStatus;
use Database\Factories\Domain\Signal\SignalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Signal extends Model implements HasMedia
{
    use BelongsToTeam, HasFactory, HasUuids, InteractsWithMedia;

    protected $fillable = [
        'team_id',
        'experiment_id',
        'contact_identity_id',
        'source_type',
        'source_identifier',
        'source_native_id',
        'payload',
        'score',
        'scoring_details',
        'metadata',
        'content_hash',
        'status',
        'project_key',
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
            'metadata' => 'array',
            'tags' => 'array',
            'score' => 'float',
            'status' => SignalStatus::class,
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

    public function contactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class);
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entity_signal')
            ->withPivot(['context', 'confidence'])
            ->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SignalComment::class)->orderBy('created_at');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4',
                'video/mp4', 'video/webm', 'video/ogg',
                'application/pdf', 'text/plain',
                'application/octet-stream', // Telegram voice notes, stickers, etc.
            ]);

        $this->addMediaConversion('thumb')
            ->width(256)
            ->height(256)
            ->queued()
            ->performOnCollections('attachments');

        $this->addMediaCollection('bug_report_files')
            ->acceptsMimeTypes([
                'image/png', 'image/jpeg', 'image/webp',
                'application/pdf', 'text/plain', 'text/csv',
            ]);
    }
}
