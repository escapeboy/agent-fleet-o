<?php

namespace App\Domain\Tool\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $host
 * @property int $port
 * @property string $fingerprint_sha256
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
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
