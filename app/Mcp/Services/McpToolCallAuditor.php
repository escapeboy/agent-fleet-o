<?php

namespace App\Mcp\Services;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Mcp\Protocol\ProtocolContext;
use App\Mcp\Tools\Compact\CompactTool;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use ReflectionClass;
use Throwable;

/**
 * Writes one AuditEntry per non-read-only MCP `tools/call`.
 *
 * Only argument keys are stored, never their values: tool arguments can carry
 * secrets, prompts or personal data. A failed audit write is reported and
 * swallowed so it never breaks the tool call itself.
 */
final class McpToolCallAuditor
{
    public const EVENT = 'mcp.tool.called';

    public function record(Tool $tool, JsonRpcRequest $request, string $outcome, ?string $errorCode): void
    {
        try {
            $arguments = $request->params['arguments'] ?? [];
            $arguments = is_array($arguments) ? $arguments : [];

            $action = null;
            $checkedClass = $tool::class;

            if ($tool instanceof CompactTool) {
                $action = isset($arguments['action']) && is_string($arguments['action']) ? $arguments['action'] : null;
                $checkedClass = $tool->actionToolClass($action) ?? $tool::class;
            }

            if (class_exists($checkedClass)
                && (new ReflectionClass($checkedClass))->getAttributes(IsReadOnly::class) !== []) {
                return;
            }

            if (! app()->bound('mcp.team_id')) {
                return;
            }

            $teamId = app('mcp.team_id');

            if (empty($teamId)) {
                return;
            }

            $user = auth()->user();
            $token = $this->resolveToken($user);

            $tokenType = match (true) {
                $token instanceof PersonalAccessToken => 'sanctum',
                $token !== null => 'passport',
                default => null,
            };

            $clientInfo = ProtocolContext::current()->clientInfo ?? [];
            $clientName = isset($clientInfo['name']) && is_string($clientInfo['name'])
                ? $clientInfo['name']
                : request()->userAgent();
            $clientVersion = isset($clientInfo['version']) && is_string($clientInfo['version'])
                ? $clientInfo['version']
                : null;

            $ocsf = OcsfMapper::classify(self::EVENT);

            AuditEntry::create([
                'team_id' => (string) $teamId,
                'user_id' => $user?->getAuthIdentifier(),
                'event' => self::EVENT,
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'subject_type' => null,
                'subject_id' => null,
                'ip_address' => request()->ip(),
                'created_at' => now(),
                'properties' => [
                    'tool' => $tool->name(),
                    'action' => $action,
                    'token_type' => $tokenType,
                    'token_id' => $token !== null ? (data_get($token, 'id') ?? data_get($token, 'oauth_access_token_id')) : null,
                    'token_name' => $token !== null ? data_get($token, 'name') : null,
                    'oauth_client_id' => $tokenType === 'passport' ? data_get($token, 'oauth_client_id', data_get($token, 'client_id')) : null,
                    'client_name' => $clientName !== null ? Str::limit($clientName, 255, '') : null,
                    'client_version' => $clientVersion !== null ? Str::limit($clientVersion, 255, '') : null,
                    'outcome' => $outcome,
                    'error_code' => $errorCode,
                    'argument_keys' => array_keys($arguments),
                ],
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    public static function outcomeFrom(JsonRpcResponse $response): array
    {
        $result = $response->toArray()['result'] ?? [];

        if (! is_array($result)) {
            return ['success', null];
        }

        if (($result['resultType'] ?? null) === 'input_required') {
            return ['input_required', null];
        }

        if (($result['isError'] ?? false) === true) {
            $text = $result['content'][0]['text'] ?? null;
            $decoded = is_string($text) ? json_decode($text, true) : null;
            $code = is_array($decoded) ? ($decoded['error']['code'] ?? null) : null;

            return ['error', is_string($code) && $code !== '' ? $code : 'TOOL_ERROR'];
        }

        return ['success', null];
    }

    /**
     * The token the caller authenticated with, as attached by the guard.
     * A session login carries a transient token, which is not a real token.
     */
    private function resolveToken(mixed $user): ?object
    {
        if (! is_object($user) || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        $token = $user->currentAccessToken();

        if (! is_object($token) || $token instanceof TransientToken) {
            return null;
        }

        return $token;
    }
}
