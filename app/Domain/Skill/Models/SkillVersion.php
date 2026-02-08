<?php

namespace App\Domain\Skill\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'skill_id',
        'version',
        'input_schema',
        'output_schema',
        'configuration',
        'changelog',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'configuration' => 'array',
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
}
