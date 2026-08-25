<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default sandbox driver
    |--------------------------------------------------------------------------
    |
    | Backend used by AgentSandbox for isolated execution of untrusted
    | experiment/agent code. "docker" is the historical default (requires a
    | Docker socket on the host — currently the shared horizon container).
    | "modal" delegates to a Modal.com Sandbox (gVisor isolation, no host
    | access) and requires MODAL_ENDPOINT_URL + MODAL_ENDPOINT_TOKEN.
    |
    */
    'driver' => env('SANDBOX_DRIVER', 'docker'),

    'drivers' => [

        'docker' => [
            // Reserved: image allowlist / default limits are enforced by
            // AgentSandboxOrchestrator and per-caller $sandboxConfig.
        ],

        'modal' => [
            /*
            | HTTPS URL of the Modal-deployed web endpoint (see modal/app.py
            | in the repo root). Example: https://<user>--fleetq-sandbox-execute.modal.run
            */
            'endpoint_url' => env('MODAL_ENDPOINT_URL'),

            /*
            | Shared bearer token — MUST match the token stored in the
            | fleetq-sandbox-auth Modal Secret used by the endpoint.
            */
            'endpoint_token' => env('MODAL_ENDPOINT_TOKEN'),

            /*
            | Fallback image if a caller does not specify sandbox.image.
            | The Modal app pins a matching digest at deploy time.
            */
            'default_image' => env('MODAL_SANDBOX_DEFAULT_IMAGE', 'python:3.12-slim'),

            /*
            | Modal region for Sandbox scheduling. EU-first per data-residency
            | requirements. Modal supports: us-east, us-west, eu, ap, etc.
            */
            'region' => env('MODAL_SANDBOX_REGION', 'eu'),

            /*
            | Overhead added on top of the per-execution timeout to bound the
            | HTTP wait time (Modal cold starts + response marshalling).
            */
            'request_overhead_seconds' => (int) env('MODAL_SANDBOX_REQUEST_OVERHEAD', 30),

            'connect_timeout_seconds' => (int) env('MODAL_SANDBOX_CONNECT_TIMEOUT', 15),
        ],

    ],

];
