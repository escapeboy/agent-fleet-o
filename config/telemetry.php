<?php

return [
    'enabled' => (bool) env('OTEL_ENABLED', false),

    'service_name' => env('OTEL_SERVICE_NAME', 'fleetq'),
    'service_version' => env('OTEL_SERVICE_VERSION', '1.0.0'),
    'deployment_environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'production')),

    'exporter' => [
        // OTLP HTTP/protobuf is the canonical OpenTelemetry transport.
        // For gRPC transport, users must install ext-grpc and switch to
        // OtlpGrpcTransportFactory — see vendor/open-telemetry docs.
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://otel-collector:4318'),
        'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),
        'timeout_seconds' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT', 5.0),
        'compression' => env('OTEL_EXPORTER_OTLP_COMPRESSION', 'gzip'),
    ],

    // 0.0..1.0 — 1.0 exports every span, 0.1 samples 10%.
    'sample_rate' => (float) env('OTEL_SAMPLE_RATE', 1.0),

    // Span attributes that must never be recorded (secrets, credentials).
    'redacted_attributes' => [
        'authorization', 'cookie', 'api_key', 'token', 'secret', 'password',
        'credential', 'bearer', 'x-api-key',
    ],
];
