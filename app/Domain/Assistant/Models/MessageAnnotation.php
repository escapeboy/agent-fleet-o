<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Assistant\Enums\AnnotationRating;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

class MessageAnnotation extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'message_id',
        'team_id',
        'user_id',
        'rating',
        'correction',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'rating' => AnnotationRating::class,
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
