<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;

interface AiGatewayInterface
{
    public function complete(AiRequestDTO $request): AiResponseDTO;

    /**
     * Stream an AI response, calling $onChunk for each text delta.
     * Returns the full AiResponseDTO when the stream completes.
     * Falls back to complete() if the provider doesn't support streaming.
     */
    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO;

    public function estimateCost(AiRequestDTO $request): int;
}
