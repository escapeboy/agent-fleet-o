<?php

namespace App\Mcp\Tools\Bitbucket\Concerns;

use Illuminate\Http\Client\RequestException;
use Laravel\Mcp\Response;

/**
 * Maps RequestException from Http::throw() into MCP structured errors.
 *
 * Consumers must also `use HasStructuredErrors`.
 */
trait MapsBitbucketHttpErrors
{
    private function mapBitbucketHttpException(RequestException $e): Response
    {
        $status = $e->response->status();

        return match (true) {
            $status === 401 || $status === 403 => $this->permissionDeniedError('Bitbucket rejected the credential.'),
            $status === 404 => $this->notFoundError('bitbucket resource'),
            $status === 429 => $this->resourceExhaustedError(
                'Bitbucket rate limit exceeded.',
                (int) ($e->response->header('Retry-After') ?: 60) * 1000,
            ),
            default => throw $e,
        };
    }
}
