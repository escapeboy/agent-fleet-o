<?php

namespace App\Domain\Credential\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedArray;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a Credential's secret_data at a point in time.
 * Created automatically by RotateCredentialSecretAction before each rotation.
 *
 * @property string $id
 * @property string $credential_id
 * @property string $team_id
 * @property array $secret_data
 * @property int $version_number
 * @property string|null $note
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class CredentialVersion extends Model
{
    use BelongsToTeam, HasUuids;

    /** Versions are immutable — no updated_at column. */
    public $timestamps = false;

    protected $fillable = [
        'credential_id',
        'team_id',
        'secret_data',
        'version_number',
        'note',
        'created_by',
        'created_at',
    ];

    protected $hidden = ['secret_data'];

    protected function casts(): array
    {
        return [
            'secret_data' => TeamEncryptedArray::class,
            'version_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
