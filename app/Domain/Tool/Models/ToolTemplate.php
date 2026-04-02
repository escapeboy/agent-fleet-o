<?php

namespace App\Domain\Tool\Models;

use App\Domain\Tool\Enums\ToolTemplateCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ToolTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'category',
        'description',
        'icon',
        'provider',
        'docker_image',
        'model_id',
        'default_input_schema',
        'default_output_schema',
        'deploy_config',
        'tool_definitions',
        'estimated_gpu',
        'estimated_cost_per_hour',
        'source_url',
        'license',
        'is_featured',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category' => ToolTemplateCategory::class,
            'default_input_schema' => 'array',
            'default_output_schema' => 'array',
            'deploy_config' => 'array',
            'tool_definitions' => 'array',
            'estimated_cost_per_hour' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCategory($query, ToolTemplateCategory $category)
    {
        return $query->where('category', $category);
    }

    public function estimatedCostDisplay(): string
    {
        if ($this->estimated_cost_per_hour === 0) {
            return 'Free (API-based)';
        }

        $dollars = $this->estimated_cost_per_hour / 1000;

        return sprintf('~$%.2f/hr GPU', $dollars);
    }
}
