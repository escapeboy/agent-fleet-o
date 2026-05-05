<?php

namespace FleetQ\BorunaAudit\Models;

use FleetQ\BorunaAudit\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property VerificationStatus $status
 * @property Carbon|null $checked_at
 * @property int|null $latency_ms
 * @property string|null $error_message
 */
class BundleVerification extends Model
{
    use HasUuids;

    protected $table = 'boruna_bundle_verifications';

    protected $fillable = [
        'team_id',
        'auditable_decision_id',
        'status',
        'checked_at',
        'error_message',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'checked_at' => 'datetime',
            'latency_ms' => 'integer',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AuditableDecision::class, 'auditable_decision_id');
    }
}
