<?php

namespace App\Domain\Signal\Contracts;

use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

interface KnowledgeConnectorInterface extends InputConnectorInterface
{
    /**
     * Returns true — marks this connector as a knowledge connector.
     * Knowledge connectors write directly to Memory and return empty arrays from poll().
     */
    public function isKnowledgeConnector(): bool;

    /**
     * Get the last successful sync timestamp for a binding.
     * Stored in Redis under key "knowledge_sync:{bindingId}".
     */
    public function getLastSyncAt(string $bindingId): ?Carbon;

    /**
     * Persist the last successful sync timestamp for a binding.
     * Stored in Redis under key "knowledge_sync:{bindingId}".
     */
    public function setLastSyncAt(string $bindingId, Carbon $at): void;
}
