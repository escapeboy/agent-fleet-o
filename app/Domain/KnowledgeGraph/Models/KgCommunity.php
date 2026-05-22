<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class KgCommunity extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'kg_communities';

    protected $fillable = [
        'team_id',
        'label',
        'summary',
        'entity_ids',
        'size',
        'top_entities',
        'summary_embedding',
    ];

    protected function casts(): array
    {
        return [
            'entity_ids' => 'array',
            'top_entities' => 'array',
            'summary_embedding' => 'array',
        ];
    }
}
