<?php

namespace App\Domain\Email\Models;

use App\Domain\Email\Enums\EmailThemeStatus;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTheme extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'status',
        'logo_url',
        'logo_width',
        'background_color',
        'canvas_color',
        'primary_color',
        'text_color',
        'heading_color',
        'muted_color',
        'divider_color',
        'font_name',
        'font_url',
        'font_family',
        'heading_font_size',
        'body_font_size',
        'line_height',
        'email_width',
        'content_padding',
        'company_name',
        'company_address',
        'footer_text',
        'is_system_default',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailThemeStatus::class,
            'logo_width' => 'integer',
            'heading_font_size' => 'integer',
            'body_font_size' => 'integer',
            'line_height' => 'float',
            'email_width' => 'integer',
            'content_padding' => 'integer',
            'is_system_default' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isActive(): bool
    {
        return $this->status === EmailThemeStatus::Active;
    }

    /** CSS font-face snippet for use in email headers */
    public function fontFaceSnippet(): string
    {
        if (! $this->font_url) {
            return '';
        }

        return "@import url('{$this->font_url}');";
    }
}
