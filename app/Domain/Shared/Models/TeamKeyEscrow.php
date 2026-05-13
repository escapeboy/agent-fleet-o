<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamKeyEscrow extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'encrypted_share',
        'share_checksum',
        'share_version',
    ];

    protected function casts(): array
    {
        return [
            'share_version' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
