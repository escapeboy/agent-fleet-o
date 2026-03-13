<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FleetQ Bridge Relay
    |--------------------------------------------------------------------------
    |
    | When relay_enabled is true, the Docker compose stack includes a fleetq-relay
    | service that accepts WebSocket connections from the fleetq-bridge daemon.
    | Agent execution requests are routed via Redis instead of the legacy PHP bridge.
    |
    | Setup:
    |   1. Set RELAY_ENABLED=true in .env
    |   2. Run: docker compose up relay
    |   3. On your host: fleetq-bridge login --api-url http://localhost:8080 --api-key <token>
    |   4. On your host: fleetq-bridge install
    |
    */

    'relay_enabled' => (bool) env('RELAY_ENABLED', false),

    // Port on the host machine where fleetq-relay listens (mapped from container :8070)
    'relay_port' => (int) env('RELAY_PORT', 8070),

];
