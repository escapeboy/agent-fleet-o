<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $label
 * @property string|null $summary
 * @property array<int, string>|null $entity_ids
 * @property int $size
 * @property array<int, array<string, mixed>>|null $top_entities
 * @property array<int, float>|null $summary_embedding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
