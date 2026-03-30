<?php

namespace App\Domain\Skill\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Enums\AnnotationRating;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores human feedback annotations on skill playground run outputs.
 * Annotations are used by GenerateImprovedSkillVersionAction to produce
 * an improved prompt template via few-shot meta-prompting.
 */
class SkillAnnotation extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'skill_version_id',
        'team_id',
        'user_id',
        'input',
        'output',
        'model_id',
        'rating',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'rating' => AnnotationRating::class,
        ];
    }

    public function skillVersion(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
