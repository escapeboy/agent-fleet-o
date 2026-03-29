<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorklogEntry extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'team_id',
        'workloggable_type',
        'workloggable_id',
        'type',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function workloggable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function validTypes(): array
    {
        return ['reference', 'finding', 'decision', 'uncertainty', 'output'];
    }
}
