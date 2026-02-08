<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;

interface AiGatewayInterface
{
    public function complete(AiRequestDTO $request): AiResponseDTO;

    public function estimateCost(AiRequestDTO $request): int;
}
