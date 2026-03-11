<?php

namespace App\Domain\Integration\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Shared HTTP response guard for integration driver execute() methods.
 *
 * Throws RequestException on authentication errors (401/403) and rate limits (429)
 * so that ExecuteIntegrationActionAction can react appropriately — e.g. marking
 * the integration as auth-failed or surfacing Retry-After delays.
 */
trait ChecksIntegrationResponse
{
    /**
     * Asserts that the HTTP response is not an auth error or rate limit.
     * Returns the response unchanged so it can be chained.
     *
     * @throws RequestException
     */
    protected function checked(Response $response): Response
    {
        if ($response->unauthorized() || $response->forbidden() || $response->tooManyRequests()) {
            $response->throw();
        }

        return $response;
    }
}
