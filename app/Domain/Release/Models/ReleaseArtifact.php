<?php

declare(strict_types=1);

namespace App\Domain\Release\Models;

use App\Models\Artifact;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReleaseArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'release_id',
        'artifact_id',
        'artifact_version',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'artifact_version' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
