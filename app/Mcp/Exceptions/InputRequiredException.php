<?php

namespace App\Mcp\Exceptions;

use App\Mcp\Protocol\RequestState;
use Laravel\Mcp\Response;

/**
 * A gated tool signalling "not done — come back with this state" (SEP-2322).
 *
 * Tools throw this unconditionally; `MultiRoundTripCallTool` decides what the
 * caller actually sees. That is why the terminal `$fallback` rides along: a
 * pre-2026-07-28 client gets today's response, an MRTR client gets an
 * `input_required` result, and the tool itself never branches on protocol
 * version.
 */
class InputRequiredException extends \RuntimeException
{
    /**
     * @param  array<string, array<string, mixed>>  $inputRequests  keyed server-side; the
     *                                                              client echoes the same keys in `inputResponses`
     * @param  Response  $fallback  what a client that cannot speak MRTR gets instead
     */
    public function __construct(
        public readonly array $inputRequests,
        public readonly ?RequestState $requestState,
        public readonly Response $fallback,
    ) {
        parent::__construct('Input required before this request can complete.');
    }

    /**
     * A pending human approval, surfaced as an elicitation so the caller's
     * operator can see what is blocked and where to act.
     *
     * The elicitation is a nudge, never the authorisation — on retry the tool
     * re-reads the approval row and decides from that. A client answering
     * "approved" without an approved row gets `input_required` again.
     */
    public static function forApproval(
        string $key,
        string $message,
        RequestState $state,
        Response $fallback,
    ): self {
        return new self(
            inputRequests: [
                $key => [
                    'method' => 'elicitation/create',
                    'params' => [
                        'mode' => 'form',
                        'message' => $message,
                        'requestedSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'acknowledged' => [
                                    'type' => 'boolean',
                                    'description' => 'Set once the approval has been actioned in FleetQ. The server re-checks the approval record regardless of this value.',
                                ],
                            ],
                            'required' => ['acknowledged'],
                        ],
                    ],
                ],
            ],
            requestState: $state,
            fallback: $fallback,
        );
    }
}
