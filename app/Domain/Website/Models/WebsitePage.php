<?php

namespace App\Domain\Website\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    ];

    protected function casts(): array
    {
        return [
            'page_type' => WebsitePageType::class,
            'status' => WebsitePageStatus::class,
            'grapes_json' => 'array',
            'meta' => 'array',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isPublished(): bool
    {
        return $this->status === WebsitePageStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->status === WebsitePageStatus::Draft;
    }

    public function getMetaTitle(): string
    {
        return $this->meta['title'] ?? $this->title;
    }

    public function getMetaDescription(): string
    {
        return $this->meta['description'] ?? '';
    }
}
