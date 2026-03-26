<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Services\DeploymentMode;
use App\Http\Controllers\Controller;
use App\Models\GlobalSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Config
 */
class LangfuseConfigController extends Controller
{
    /**
     * Get the effective Langfuse LLMOps configuration.
     *
     * Returns merged config: database overrides take precedence over env values.
     * API keys are never returned — only whether they are set.
     */
    public function show(): JsonResponse
    {
        /** @var array<string, mixed> $overrides */
        $overrides = (array) (GlobalSetting::get('langfuse_config') ?? []);

        $envPublicKey = config('llmops.langfuse.public_key', '');
        $envSecretKey = config('llmops.langfuse.secret_key', '');

        return response()->json([
            'data' => [
                'enabled' => isset($overrides['enabled']) ? (bool) $overrides['enabled'] : config('llmops.langfuse.enabled', false),
                'host' => (string) ($overrides['host'] ?? config('llmops.langfuse.host', 'https://cloud.langfuse.com')),
                'public_key_set' => ! empty($overrides['public_key'] ?? $envPublicKey),
                'secret_key_set' => ! empty($overrides['secret_key'] ?? $envSecretKey),
                'mask_content' => isset($overrides['mask_content']) ? (bool) $overrides['mask_content'] : config('llmops.langfuse.mask_content', false),
                'source' => empty($overrides) ? 'env' : 'database_override',
            ],
        ]);
    }

    /**
     * Update the Langfuse LLMOps configuration.
     *
     * Writes a database override that takes precedence over env values.
     * In cloud deployments only super admins may call this endpoint.
     */
    public function update(Request $request): JsonResponse
    {
        if (app(DeploymentMode::class)->isCloud() && ! $request->user()?->is_super_admin) {
            abort(403, 'Super admin privileges required.');
        }

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'host' => ['sometimes', 'string', 'url', 'starts_with:https://'],
            'public_key' => ['sometimes', 'string', 'max:500'],
            'secret_key' => ['sometimes', 'string', 'max:500'],
            'mask_content' => ['sometimes', 'boolean'],
        ]);

        /** @var array<string, mixed> $current */
        $current = (array) (GlobalSetting::get('langfuse_config') ?? []);

        foreach (['enabled', 'host', 'mask_content'] as $field) {
            if (array_key_exists($field, $validated)) {
                $current[$field] = $validated[$field];
            }
        }

        foreach (['public_key', 'secret_key'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== '') {
                $current[$field] = $validated[$field];
            }
        }

        GlobalSetting::set('langfuse_config', $current);

        return response()->json([
            'data' => [
                'enabled' => $current['enabled'] ?? config('llmops.langfuse.enabled', false),
                'host' => $current['host'] ?? config('llmops.langfuse.host', 'https://cloud.langfuse.com'),
                'public_key_set' => ! empty($current['public_key'] ?? config('llmops.langfuse.public_key', '')),
                'secret_key_set' => ! empty($current['secret_key'] ?? config('llmops.langfuse.secret_key', '')),
                'mask_content' => $current['mask_content'] ?? config('llmops.langfuse.mask_content', false),
                'source' => 'database_override',
            ],
        ]);
    }
}
