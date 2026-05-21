<?php

namespace App\Domain\Website\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $website_id
 * @property string $team_id
 * @property string $slug
 * @property string $title
 * @property WebsitePageType $page_type
 * @property WebsitePageStatus $status
 * @property array<string, mixed>|null $grapes_json
 * @property string|null $exported_html
 * @property string|null $exported_css
 * @property array<string, mixed>|null $meta
 * @property int $sort_order
 * @property Carbon|null $published_at
 * @property string|null $form_id
 */
class WebsitePage extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'website_id',
        'team_id',
        'slug',
        'title',
        'page_type',
        'status',
        'grapes_json',
        'exported_html',
        'exported_css',
        'meta',
        'sort_order',
        'published_at',
        'form_id',
    ];

    protected $casts = [
        'page_type' => WebsitePageType::class,
        'status' => WebsitePageStatus::class,
        'grapes_json' => 'array',
        'meta' => 'array',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
