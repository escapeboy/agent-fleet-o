# Sandbox: Modal driver

FleetQ's `AgentSandbox` isolates untrusted experiment / agent code execution
behind a driver-selectable backend. `docker` is the historical default and
runs inside whatever container FleetQ is deployed in (currently the shared
horizon container as `root`, with `.env` mounted — the surface this driver is
designed to eliminate). `modal` delegates each execution to a fresh Modal
Sandbox (gVisor isolation, no host access, hard timeout).

## Architecture

```
Skill\Actions\ExecuteCodeExecutionSkillAction
        │
        ▼
Domain\Agent\Services\AgentSandbox
        │  (delegates)
        ▼
Domain\Agent\Contracts\SandboxDriverInterface
        │
        ├── Sandbox\DockerSandboxDriver   (SANDBOX_DRIVER=docker, default)
        └── Sandbox\ModalSandboxDriver    (SANDBOX_DRIVER=modal)
                                                 │
                                                 ▼ HTTPS (bearer token)
                                        modal/app.py (Modal deployment)
                                                 │
                                                 ▼
                                        modal.Sandbox (gVisor, EU region,
                                        network-blocked, per-call teardown)
```

## Enable

Prerequisites (only if operating the Modal backend — the code compiles and
runs without them, the driver just fails when selected):

1. Modal account with a valid token (`modal setup`).
2. `modal secret create fleetq-sandbox-auth token=<random>` — used by the
   Python endpoint to authenticate incoming requests.
3. `modal deploy modal/app.py` — prints the endpoint URL.

Laravel `.env`:

```
SANDBOX_DRIVER=modal
MODAL_ENDPOINT_URL=https://<user>--fleetq-sandbox-execute.modal.run
MODAL_ENDPOINT_TOKEN=<same random value as the Modal secret>
MODAL_SANDBOX_DEFAULT_IMAGE=python:3.12-slim
MODAL_SANDBOX_REGION=eu
```

## Cost model

Modal Sandboxes bill at the non-preemptible rate (roughly 3x the spot
rate) because Modal does not schedule Sandboxes on preemptible workers. Each
`ModalSandboxDriver::execute()` call logs a `cost_estimate_usd` and a
`duration_ms` — the estimate is derived from Modal's published per-second
CPU / GB rates and rounded to six decimal places. Use it as an audit-trail
hint; the authoritative bill is the Modal dashboard.

Rules of thumb (subject to Modal's pricing page):

- 256 MB / 0.5 vCPU / 5 s ≈ $0.00007
- 512 MB / 1 vCPU / 30 s ≈ $0.0009
- 2 GB / 2 vCPU / 5 min ≈ $0.09

## When to prefer Modal vs Docker

| Scenario | Driver |
|----------|--------|
| Cloud production where horizon must not have Docker socket / `.env` visibility from executed code | **Modal** |
| Experiment code that touches third-party HTTP APIs and must not share the host network with FleetQ services | **Modal** |
| Compliance / audit requirements around isolated tenants | **Modal** |
| Local dev, CI test doubles, latency-sensitive short scripts | Docker |

## Security invariants (Modal side)

- gVisor is forced (`gvisor=True` in `modal/app.py`).
- `block_network=True` on every Sandbox — matches the Docker driver's
  `--network none` default. An extension to expose a network mode must
  update both drivers and the doc.
- Image prefix allowlist mirrors `AgentSandboxOrchestrator::ALLOWED_IMAGE_PREFIXES`.
- Bearer token check on every request; missing/invalid → 401.
- Workspace upload capped at 512 files / 8 MB; path traversal rejected.
- Hard timeout capped at 900 s server-side even if the caller asks for more.

## Attack surface intentionally NOT reached

The Modal Sandbox has no bind mount of the horizon workspace: files are
copied over the HTTPS boundary into a fresh Sandbox root. There is no path
by which sandboxed code can reach `.env`, Redis, MariaDB, or any other
service running on the FleetQ host — the network is blocked, the filesystem
is fresh, and the Sandbox is terminated on return.

Attack tests in `base/tests/Unit/Sandbox/ModalSandboxDriverTest.php` and
`base/tests/Feature/Sandbox/SandboxDriverBindingTest.php` exercise this
contract at the driver / container layer without needing a live Modal
deployment.

## Rollout

Modal is opt-in per-environment. The intended rollout is:

1. Deploy the Python app to Modal (staging Modal workspace first).
2. Set `SANDBOX_DRIVER=modal` on a staging environment. Verify one warm-build
   run end-to-end.
3. Repeat on production with the same environment variable flip; no code
   change on the Laravel side is required to switch back.
