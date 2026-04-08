<?php

namespace App\Domain\Website\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAsset extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'website_id',
        'team_id',
        'filename',
        'disk',
        'path',
        'url',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
