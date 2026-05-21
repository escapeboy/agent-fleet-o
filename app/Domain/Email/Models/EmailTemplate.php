<?php

namespace App\Domain\Email\Models;

use App\Domain\Email\Enums\EmailTemplateStatus;
use App\Domain\Email\Enums\EmailTemplateVisibility;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string|null $email_theme_id
 * @property string $name
 * @property string|null $subject
 * @property string|null $preview_text
 * @property array<string, mixed>|null $design_json
 * @property string|null $html_cache
 * @property EmailTemplateStatus $status
 * @property EmailTemplateVisibility $visibility
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class EmailTemplate extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'email_theme_id',
        'name',
        'subject',
        'preview_text',
        'design_json',
        'html_cache',
        'status',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailTemplateStatus::class,
            'visibility' => EmailTemplateVisibility::class,
            'design_json' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function emailTheme(): BelongsTo
    {
        return $this->belongsTo(EmailTheme::class);
    }

    public function isActive(): bool
    {
        return $this->status === EmailTemplateStatus::Active;
    }

    public function isPublic(): bool
    {
        return $this->visibility === EmailTemplateVisibility::Public;
    }
}
