<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use Closure;

interface AiMiddlewareInterface
{
    /**
     * @param  Closure(AiRequestDTO): AiResponseDTO  $next
     */
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO;
}
