<?php

namespace App\Domain\Webhook\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebhookEndpoint extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'headers',
        'retry_config',
        'last_triggered_at',
        'failure_count',
        'signature_header',
        'signature_format',
        'signature_algo',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'headers' => 'array',
            'retry_config' => 'array',
            'is_active' => 'boolean',
            'secret' => TeamEncryptedString::class,
            'last_triggered_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    public function subscribesTo(string $event): bool
    {
        $events = $this->events ?? [];

        return in_array($event, $events) || in_array('*', $events);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function recordSuccess(): void
    {
        $this->update([
            'last_triggered_at' => now(),
            'failure_count' => 0,
        ]);
    }

    public function recordFailure(): void
    {
        $this->increment('failure_count');
        $this->update(['last_triggered_at' => now()]);
    }
}
