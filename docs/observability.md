# Observability — OpenTelemetry setup

FleetQ emits OpenTelemetry spans for LLM calls, MCP tool invocations, pipeline
stages, and queue jobs. The tracer is **off by default**. Flip `OTEL_ENABLED=true`
and point `OTEL_EXPORTER_OTLP_ENDPOINT` at any OTLP HTTP/protobuf collector —
no code changes required.

## Env vars

```ini
OTEL_ENABLED=true
OTEL_SERVICE_NAME=fleetq
OTEL_SERVICE_VERSION=1.0.0
OTEL_DEPLOYMENT_ENVIRONMENT=production

OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_TIMEOUT=5.0
OTEL_EXPORTER_OTLP_COMPRESSION=gzip
OTEL_EXPORTER_OTLP_HEADERS=           # key=value,key=value (optional, for auth)

OTEL_SAMPLE_RATE=1.0                  # 0.0..1.0 — lower in high-traffic prod
```

All values are read by `config/telemetry.php`. The tracer writes an OTLP HTTP
request to `<ENDPOINT>/v1/traces` using `application/x-protobuf`.

## Provider recipes

### Pydantic Logfire (cloud)

Managed OTel backend with built-in LLM dashboards, eval curation, and
production trace inspection. Free tier available.

1. Sign up at <https://logfire.pydantic.dev/> and create a project.
2. In project settings → **Write tokens** → copy the token.
3. Set env vars:

```ini
OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=https://logfire-api.pydantic.dev
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer ${LOGFIRE_WRITE_TOKEN}
```

EU data region: use `https://logfire-eu.pydantic.dev` instead.

### Honeycomb

```ini
OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=https://api.honeycomb.io
OTEL_EXPORTER_OTLP_HEADERS=x-honeycomb-team=${HONEYCOMB_API_KEY}
```

### Grafana Tempo / Grafana Cloud

```ini
OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=https://tempo-prod-04-prod-us-east-0.grafana.net
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Basic ${GRAFANA_BASIC_AUTH_B64}
```

### SigNoz (self-hosted)

```ini
OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=http://signoz-otel-collector:4318
# No auth headers — typical for in-cluster SigNoz deployments.
```

### Jaeger (self-hosted, development)

Jaeger 1.35+ accepts OTLP natively:

```ini
OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4318
```

### Local dev via otel-collector

```ini
OTEL_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
```

Run the collector alongside your stack:

```yaml
# docker-compose.override.yml
services:
  otel-collector:
    image: otel/opentelemetry-collector-contrib:latest
    command: ["--config=/etc/otel-collector-config.yaml"]
    volumes:
      - ./otel-collector-config.yaml:/etc/otel-collector-config.yaml
    ports:
      - "4318:4318"
```

## What gets traced

| Span kind | Attributes |
|---|---|
| `llm.request` | provider, model, purpose, prompt_tokens, completion_tokens, cost_credits, team_id |
| `mcp.tool.call` | tool_name, duration_ms, status, arguments_hash |
| `experiment.stage` | experiment_id, stage_type, status, duration_ms |
| `queue.job` | job_class, queue, attempt, duration_ms |
| `pipeline.middleware` | middleware_name, pipeline, duration_ms |

Secret attributes listed in `config/telemetry.redacted_attributes` are
stripped before export.

## Sampling

`OTEL_SAMPLE_RATE` uses `ParentBased(TraceIdRatioBased)` — if the incoming
request already carries a sampled trace, we keep the decision; otherwise
we sample at the configured ratio.

For high-traffic production deployments, start at `0.1` (10 % of traces) and
raise if span volume allows.

## Troubleshooting

**No spans appear in backend:**
- Confirm `OTEL_ENABLED=true` in the env of every container that runs code
  (app, horizon, scheduler).
- `docker compose exec app env | grep OTEL` — the env var must be present at
  runtime, not just in `.env`.
- Check `storage/logs/laravel.log` for `TracerProvider: failed to initialize`
  — exporter errors are logged, then the app falls back to noop silently
  (does not crash the request).

**Auth 401/403:**
- Double-check `OTEL_EXPORTER_OTLP_HEADERS` — format is
  `key1=value1,key2=value2` with no quotes or spaces around the `=`.
- For Logfire write tokens: the header value starts with `Bearer `.

**Span volume too high / bill spiking:**
- Lower `OTEL_SAMPLE_RATE` (e.g. `0.1`).
- Narrow down in your backend's UI (Logfire `service.name == fleetq`).

**Self-hosting Logfire:**
- The Python SDK is MIT and open-source; the backend is SaaS only.
- If you need fully self-hosted, switch to SigNoz, Jaeger, or Grafana Tempo
  (all OTLP-compatible — same env vars work).
