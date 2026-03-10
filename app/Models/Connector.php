<?php

namespace App\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Infrastructure\Encryption\Casts\TeamEncryptedArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Connector extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'type',
        'driver',
        'name',
        'config',
        'status',
        'last_success_at',
        'last_error_at',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'config' => TeamEncryptedArray::class,
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
