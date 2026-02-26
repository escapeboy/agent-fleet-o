<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;

class DisconnectIntegrationAction
{
    public function execute(Integration $integration): void
    {
        $integration->webhookRoutes()->delete();

        $integration->update(['status' => IntegrationStatus::Disconnected]);
        $integration->delete();
    }
}
