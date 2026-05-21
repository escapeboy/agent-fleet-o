<?php

namespace App\Domain\Email\Models;

use App\Domain\Email\Enums\EmailThemeStatus;
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
 * @property string $name
 * @property EmailThemeStatus $status
 * @property string|null $logo_url
 * @property int $logo_width
 * @property string $background_color
 * @property string $canvas_color
 * @property string $primary_color
 * @property string $text_color
 * @property string $heading_color
 * @property string $muted_color
 * @property string $divider_color
 * @property string $font_name
 * @property string|null $font_url
 * @property string $font_family
 * @property int $heading_font_size
 * @property int $body_font_size
 * @property float $line_height
 * @property int $email_width
 * @property int $content_padding
 * @property string|null $company_name
 * @property string|null $company_address
 * @property string|null $footer_text
 * @property bool $is_system_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
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
