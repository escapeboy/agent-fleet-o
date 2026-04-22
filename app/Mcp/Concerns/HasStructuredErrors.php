<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Mcp\ErrorCode;
use Laravel\Mcp\Response;

/**
 * Helpers for concrete MCP tools to return structured error payloads.
 *
 * The central CompactTool wrapper already classifies uncaught exceptions,
 * but many concrete tools catch business-rule violations locally and
 * return Response::error("plain string"). This trait provides typed
 * helpers so those returns carry the canonical error code + retryable
 * hint that agents use to decide whether to retry.
 *
 * Usage:
 *
 *   use App\Mcp\Concerns\HasStructuredErrors;
 *   use Laravel\Mcp\Server\Tool;
 *
 *   class AgentGetTool extends Tool {
 *       use HasStructuredErrors;
 *
 *       public function handle(Request $request): Response {
 *           $agent = Agent::find($request->get('id'));
 *           if (! $agent) {
 *               return $this->notFoundError('agent', $request->get('id'));
 *           }
 *           // ...
 *       }
 *   }
 */
trait HasStructuredErrors
{
    /**
     * Build a structured error Response with a canonical code.
     *
     * @param  array<string, mixed>|null  $details
     */
    protected function errorResponse(
        ErrorCode $code,
        string $message,
        ?int $retryAfterMs = null,
        ?array $details = null,
    ): Response {
        $error = [
            'code' => $code->value,
            'message' => $message,
            'retryable' => $code->isRetryable(),
        ];

        if ($retryAfterMs !== null) {
            $error['retry_after_ms'] = $retryAfterMs;
        }

        if ($details !== null && $details !== []) {
            $error['details'] = $details;
        }

        return Response::error(json_encode(
            ['error' => $error],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    protected function notFoundError(string $entity, ?string $id = null): Response
    {
        $message = $id === null
            ? ucfirst($entity).' not found.'
            : ucfirst($entity)." '{$id}' not found.";

        return $this->errorResponse(ErrorCode::NotFound, $message);
    }

    protected function permissionDeniedError(string $reason = 'Permission denied.'): Response
    {
        return $this->errorResponse(ErrorCode::PermissionDenied, $reason);
    }

    /**
     * @param  array<string, list<string>>|null  $validationErrors
     */
    protected function invalidArgumentError(string $message, ?array $validationErrors = null): Response
    {
        return $this->errorResponse(
            ErrorCode::InvalidArgument,
            $message,
            details: $validationErrors !== null ? ['fields' => $validationErrors] : null,
        );
    }

    protected function failedPreconditionError(string $reason): Response
    {
        return $this->errorResponse(ErrorCode::FailedPrecondition, $reason);
    }

    protected function resourceExhaustedError(string $reason, ?int $retryAfterMs = null): Response
    {
        return $this->errorResponse(ErrorCode::ResourceExhausted, $reason, $retryAfterMs);
    }

    protected function unavailableError(string $service = 'Upstream service unavailable.'): Response
    {
        return $this->errorResponse(ErrorCode::Unavailable, $service);
    }

    protected function deadlineExceededError(string $reason = 'Tool call exceeded the allotted deadline.'): Response
    {
        return $this->errorResponse(ErrorCode::DeadlineExceeded, $reason);
    }
}
