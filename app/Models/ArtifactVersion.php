<?php

namespace App\Models;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Credential\Concerns\RedactsSecretsBeforePersist;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactVersion extends Model
{
    use BelongsToTeam, HasUuids, RedactsSecretsBeforePersist;

    protected $fillable = [
        'team_id',
        'artifact_id',
        'version',
        'content',
        'metadata',
        'created_by_ai_run',
    ];

    /**
     * @return list<string>
     */
    protected function redactableAttributes(): array
    {
        return ['content'];
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'created_by_ai_run');
    }
}
