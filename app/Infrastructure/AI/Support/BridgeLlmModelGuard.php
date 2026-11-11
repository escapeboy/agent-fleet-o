<?php

namespace App\Infrastructure\AI\Support;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Infrastructure\AI\Exceptions\BridgeModelNotAvailableException;

/**
 * Rejects a bridge_llm request whose model is not served by any online
 * LLM endpoint of the chosen bridge connection, before it is dispatched.
 */
class BridgeLlmModelGuard
{
    public function assertModelAvailable(BridgeConnection $connection, string $model): void
    {
        // No online endpoints: leave it to the existing fallback behaviour.
        if ($connection->onlineLlmCount() === 0) {
            return;
        }

        // An online endpoint without a model list may serve anything — skip the check.
        if ($connection->hasOnlineLlmEndpointWithoutModels()) {
            return;
        }

        $available = $connection->onlineLlmModels();

        if (in_array($model, $available, true)) {
            return;
        }

        throw new BridgeModelNotAvailableException($model, $available);
    }
}
