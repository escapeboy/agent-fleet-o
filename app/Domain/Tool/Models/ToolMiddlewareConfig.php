<?php

namespace App\Domain\Tool\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration for a middleware attached to a specific tool.
 *
 * @property string $tool_id
 * @property string $middleware_class
 * @property string $label
 * @property array $config
 * @property int $priority
 * @property bool $enabled
 */
class ToolMiddlewareConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'tool_id',
        'middleware_class',
        'label',
        'config',
        'priority',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }
}
