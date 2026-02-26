<?php

namespace App\Domain\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookRoute extends Model
{
    use HasUuids;

    protected $fillable = [
        'integration_id',
        'slug',
        'signing_secret',
        'subscribed_events',
        'is_active',
    ];

    protected $hidden = ['signing_secret'];

    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'subscribed_events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
