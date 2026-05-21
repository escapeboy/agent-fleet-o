<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property string $team_id
 * @property string $signal_id
 * @property string|null $user_id
 * @property string $author_type
 * @property string $body
 * @property string|null $idempotency_key
 * @property bool $widget_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Signal|null $signal
 * @property-read User|null $user
 */
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
        'idempotency_key',
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
