<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SignalComment extends Model implements HasMedia
{
    use BelongsToTeam, HasUuids, InteractsWithMedia;

    protected $fillable = [
        'team_id',
        'signal_id',
        'user_id',
        'author_type',
        'body',
        'widget_visible',
    ];

    protected function casts(): array
    {
        return [
            'widget_visible' => 'boolean',
        ];
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(320)
            ->height(320)
            ->queued();
    }
}
