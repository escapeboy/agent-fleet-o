<?php

namespace App\Domain\Migration\Models;

use App\Domain\Migration\Enums\MigrationEntityType;
use App\Domain\Migration\Enums\MigrationSource;
use App\Domain\Migration\Enums\MigrationStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MigrationRun extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'entity_type',
        'source',
        'source_bytes',
        'source_payload',
        'file_path',
        'proposed_mapping',
        'confirmed_mapping',
        'status',
        'stats',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => MigrationEntityType::class,
            'source' => MigrationSource::class,
            'status' => MigrationStatus::class,
            'proposed_mapping' => 'array',
            'confirmed_mapping' => 'array',
            'stats' => 'array',
            'errors' => 'array',
            'source_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function effectiveMapping(): array
    {
        return $this->confirmed_mapping ?? $this->proposed_mapping ?? [];
    }
}
