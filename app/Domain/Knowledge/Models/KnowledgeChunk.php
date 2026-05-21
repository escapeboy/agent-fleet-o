<?php

namespace App\Domain\Knowledge\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $knowledge_base_id
 * @property string $content
 * @property string $source_name
 * @property string $source_type
 * @property array<string, mixed>|null $metadata
 * @property mixed $embedding pgvector column (string|null at PHP level; not cast)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read KnowledgeBase|null $knowledgeBase
 */
class KnowledgeChunk extends Model
{
    use HasUuids;

    protected $fillable = [
        'knowledge_base_id',
        'content',
        'source_name',
        'source_type',
        'metadata',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
