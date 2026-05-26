<?php

namespace App\Domain\Approval\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalVote extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'approval_request_id',
        'user_id',
        'decision',
        'notes',
    ];

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
