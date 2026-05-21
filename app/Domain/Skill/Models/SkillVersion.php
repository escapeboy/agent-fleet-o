<?php

namespace App\Domain\Skill\Models;

use App\Models\User;
use Database\Factories\Domain\Skill\SkillVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $skill_id
 * @property string $version
 * @property array<string, mixed>|null $input_schema
 * @property array<string, mixed>|null $output_schema
 * @property array<string, mixed>|null $configuration
 * @property string|null $changelog
 * @property string|null $parent_version_id
 * @property string|null $evolution_type
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SkillVersion extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return SkillVersionFactory::new();
    }

    protected $fillable = [
        'skill_id',
        'version',
        'input_schema',
        'output_schema',
        'configuration',
        'changelog',
        'parent_version_id',
        'evolution_type',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'configuration' => 'array',
            'evolution_type' => 'string',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'parent_version_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SkillVersion::class, 'parent_version_id');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(SkillAnnotation::class);
    }
}
