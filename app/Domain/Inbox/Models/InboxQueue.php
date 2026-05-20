<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxQueue extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'name',
        'slug',
        'filter_rules',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'filter_rules' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convenience accessor — kinds the queue accepts.
     *
     * @return array<int, string>
     */
    public function allowedKinds(): array
    {
        return (array) ($this->filter_rules['kinds'] ?? []);
    }

    public function minRiskScore(): ?float
    {
        $value = $this->filter_rules['min_risk_score'] ?? null;

        return $value !== null ? (float) $value : null;
    }
}
