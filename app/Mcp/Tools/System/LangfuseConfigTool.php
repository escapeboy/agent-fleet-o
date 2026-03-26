<?php

namespace App\Mcp\Tools\System;

use App\Domain\Shared\Services\DeploymentMode;
use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

class LangfuseConfigTool extends Tool
{
    protected string $name = 'langfuse_config';

    protected string $description = 'Get or update Langfuse LLMOps trace export configuration. Langfuse receives a trace for every LLM gateway call (fire-and-forget). Actions: get — read current config; update — write new values (admin/owner only).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: get | update')
                ->enum(['get', 'update'])
                ->required(),
            'enabled' => $schema->boolean()
                ->description('Enable or disable Langfuse export (update only)'),
            'host' => $schema->string()
                ->description('Langfuse host URL, e.g. https://cloud.langfuse.com (update only)'),
            'public_key' => $schema->string()
                ->description('Langfuse public key (update only — stored encrypted in DB)'),
            'secret_key' => $schema->string()
                ->description('Langfuse secret key (update only — stored encrypted in DB)'),
            'mask_content' => $schema->boolean()
                ->description('Replace prompts with [REDACTED] before export to protect PII (update only)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->input('action', 'get');

        if ($action === 'get') {
            return $this->handleGet();
        }

        if ($action === 'update') {
            return $this->handleUpdate($request);
        }

        return Response::error("Unknown action: {$action}");
    }

    #[IsReadOnly]
    #[IsIdempotent]
    private function handleGet(): Response
    {
        /** @var array<string, mixed> $overrides */
        $overrides = (array) (GlobalSetting::get('langfuse_config') ?? []);

        $effectiveEnabled = isset($overrides['enabled']) ? (bool) $overrides['enabled'] : config('llmops.langfuse.enabled', false);
        $effectiveHost = (string) ($overrides['host'] ?? config('llmops.langfuse.host', 'https://cloud.langfuse.com'));
        $effectiveMaskContent = isset($overrides['mask_content']) ? (bool) $overrides['mask_content'] : config('llmops.langfuse.mask_content', false);

        $envPublicKey = config('llmops.langfuse.public_key', '');
        $envSecretKey = config('llmops.langfuse.secret_key', '');

        return Response::text(json_encode([
            'enabled' => $effectiveEnabled,
            'host' => $effectiveHost,
            'public_key_set' => ! empty($overrides['public_key'] ?? $envPublicKey),
            'secret_key_set' => ! empty($overrides['secret_key'] ?? $envSecretKey),
            'mask_content' => $effectiveMaskContent,
            'source' => empty($overrides) ? 'env' : 'database_override',
            'note' => 'Keys are not returned for security. public_key_set / secret_key_set indicate whether they are configured.',
        ]));
    }

    private function handleUpdate(Request $request): Response
    {
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return Response::error('Access denied: super admin privileges required.');
        }

        /** @var array<string, mixed> $current */
        $current = (array) (GlobalSetting::get('langfuse_config') ?? []);

        if ($request->has('enabled')) {
            $current['enabled'] = (bool) $request->input('enabled');
        }

        if ($request->has('host')) {
            $host = (string) $request->input('host');
            if (parse_url($host, PHP_URL_SCHEME) !== 'https') {
                return Response::error('host must use https.');
            }
            $current['host'] = $host;
        }

        if ($request->has('public_key') && $request->input('public_key') !== '') {
            $current['public_key'] = (string) $request->input('public_key');
        }

        if ($request->has('secret_key') && $request->input('secret_key') !== '') {
            $current['secret_key'] = (string) $request->input('secret_key');
        }

        if ($request->has('mask_content')) {
            $current['mask_content'] = (bool) $request->input('mask_content');
        }

        GlobalSetting::set('langfuse_config', $current);

        return Response::text(json_encode([
            'success' => true,
            'enabled' => $current['enabled'] ?? config('llmops.langfuse.enabled', false),
            'host' => $current['host'] ?? config('llmops.langfuse.host', 'https://cloud.langfuse.com'),
            'public_key_set' => ! empty($current['public_key'] ?? config('llmops.langfuse.public_key', '')),
            'secret_key_set' => ! empty($current['secret_key'] ?? config('llmops.langfuse.secret_key', '')),
            'mask_content' => $current['mask_content'] ?? config('llmops.langfuse.mask_content', false),
        ]));
    }
}
