<?php

namespace App\Infrastructure\AI\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a sampled "shadow" comparison: the same prompt run against a candidate
 * model alongside the primary, for offline A/B without ever serving the shadow
 * output. Written by RunShadowComparisonJob, never on the primary request path.
 */
class ShadowComparison extends Model
{
    use BelongsToTeam, HasUuids, MassPrunable;

    public function prunable(): Builder
    {
        // Telemetry — retain 30 days, then prune.
        return static::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays(30));
    }

    protected $fillable = [
        'team_id',
        'purpose',
        'prompt_hash',
        'primary_provider',
        'primary_model',
        'primary_latency_ms',
        'primary_cost_credits',
        'primary_output_hash',
        'primary_output_chars',
        'shadow_provider',
        'shadow_model',
        'shadow_status',
        'shadow_latency_ms',
        'shadow_cost_credits',
        'shadow_output_hash',
        'shadow_output_chars',
        'shadow_error',
        'outputs_match',
        'primary_snippet',
        'shadow_snippet',
    ];

    protected function casts(): array
    {
        return [
            'primary_latency_ms' => 'integer',
            'primary_cost_credits' => 'integer',
            'primary_output_chars' => 'integer',
            'shadow_latency_ms' => 'integer',
            'shadow_cost_credits' => 'integer',
            'shadow_output_chars' => 'integer',
            'outputs_match' => 'boolean',
        ];
    }
}
