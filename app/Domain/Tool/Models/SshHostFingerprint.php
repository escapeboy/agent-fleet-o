<?php

namespace App\Domain\Tool\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SshHostFingerprint extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'host',
        'port',
        'fingerprint_sha256',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'verified_at' => 'datetime',
        ];
    }
}
