<?php

namespace App\Domain\Audit\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $user_id
 * @property string $event
 * @property int|null $ocsf_class_uid
 * @property int|null $ocsf_severity_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property string|null $decision_context
 * @property string|null $triggered_by
 * @property string|null $impersonator_id
 */
class AuditEntry extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'user_id',
        'event',
        'ocsf_class_uid',
        'ocsf_severity_id',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'created_at',
        'decision_context',
        'triggered_by',
        'impersonator_id',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
            'ocsf_class_uid' => 'integer',
            'ocsf_severity_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
