<?php

namespace App\Domain\Tool\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolEmbedding extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'tool_id',
        'prism_tool_name',
        'text_content',
        'embedding',
    ];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }
}
