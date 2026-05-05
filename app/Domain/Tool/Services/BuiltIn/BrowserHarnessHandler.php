<?php

namespace App\Domain\Tool\Services\BuiltIn;

use App\Domain\Agent\Services\DockerSandboxExecutor;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Tool\Models\Toolset;
use Illuminate\Support\Str;

/**
 * Self-healing browser harness (build #4, Trendshift top-5 sprint).
 *
 * Inspired by browser-use/browser-harness — the agent gets a thin CDP harness
 * (daemon + helpers) and may patch helpers.py mid-task. Optionally persists
 * helpers back to a Toolset (gated by `browser_helpers_pending_review` until
 * a human approves).
 *
 * The actual chromium sidecar is launched inside the DockerSandboxExecutor's
 * workspace; this handler is the orchestration layer (seed templates, apply
 * helper diff, kick off the run, capture results, optionally stage helpers).
 */
class BrowserHarnessHandler
{
    public function __construct(
        private readonly DockerSandboxExecutor $executor,
    ) {}

    /**
     * @param  array{task: string, helpers_diff?: string|null, persist_helpers?: bool, toolset_id?: string|null}  $params
     * @return array{ok: bool, output?: string, error?: string, persisted_pending?: bool}
     */
    public function execute(array $params, string $teamId, ?SandboxedWorkspace $workspace = null): array
    {
        if (! config('browser.harness_enabled', false)) {
            return ['ok' => false, 'error' => 'browser harness is disabled — set BROWSER_HARNESS_ENABLED=true and rebuild the sandbox image with chromium installed'];
        }

        $task = trim($params['task']);
        if ($task === '') {
            return ['ok' => false, 'error' => 'task required'];
        }

        $workspace ??= new SandboxedWorkspace(Str::uuid()->toString(), 'browser_harness', $teamId);

        // Compose the helpers.py source from (a) starter helpers, (b) approved helpers
        // pulled from the linked Toolset, (c) the agent's per-call diff.
        $helpers = $this->starterHelpers();

        $toolsetId = $params['toolset_id'] ?? null;
        if ($toolsetId !== null) {
            $helpers .= "\n\n# --- Approved helpers from Toolset ---\n".$this->loadApprovedHelpers($toolsetId, $teamId);
        }

        $diff = $params['helpers_diff'] ?? null;
        if (is_string($diff) && $diff !== '') {
            // We treat `helpers_diff` as a literal Python snippet to append. Real
            // unified-diff support is a P2 — keeps the surface area small for MVP.
            $helpers .= "\n\n# --- Agent-added helpers (this run) ---\n".$diff;
        }

        // Build the inline command — write helpers.py + run task in one shot.
        // The DockerSandboxExecutor enforces no-network by default.
        $taskJson = json_encode(['task' => $task], JSON_UNESCAPED_SLASHES);
        $command = sprintf(
            "mkdir -p /workspace && cat > /workspace/helpers.py <<'PYEOF'\n%s\nPYEOF\n".
            'chromium-browser --headless --disable-gpu --no-sandbox --remote-debugging-port=9222 '.
            '> /tmp/chrome.log 2>&1 & sleep 1 && '.
            'python3 -c "import helpers; helpers.run_task(%s)" 2>&1',
            $helpers,
            escapeshellarg($taskJson),
        );

        $result = $this->executor->execute($command, $workspace, timeoutSeconds: 180);

        $persistedPending = false;
        if (! empty($params['persist_helpers']) && $diff !== null && $toolsetId !== null) {
            $this->stagePendingHelpers($toolsetId, $teamId, $diff);
            $persistedPending = true;
        }

        return array_merge($result, ['persisted_pending' => $persistedPending]);
    }

    /**
     * Minimal starter vocabulary — kept tiny and inlined so we don't depend
     * on copying upstream files. The agent extends as needed via helpers_diff.
     */
    private function starterHelpers(): string
    {
        return <<<'PY'
        # Browser Harness starter helpers (FleetQ).
        # Agents may extend this file via helpers_diff parameter.
        import json
        import subprocess

        USED_HELPERS = []

        def _track(name):
            if name not in USED_HELPERS:
                USED_HELPERS.append(name)

        def navigate(url):
            _track("navigate")
            # CDP: Page.navigate via curl websocket — placeholder body for sandbox testing.
            return {"ok": True, "url": url}

        def screenshot():
            _track("screenshot")
            return {"ok": True, "data_b64": ""}

        def eval_js(expr):
            _track("eval_js")
            return {"ok": True, "result": None, "expr": expr}

        def run_task(task):
            # The agent must redefine this via helpers_diff for non-trivial tasks.
            print(json.dumps({"ok": True, "task": task, "used_helpers": USED_HELPERS}))
        PY;
    }

    private function loadApprovedHelpers(string $toolsetId, string $teamId): string
    {
        /** @var Toolset|null $toolset */
        $toolset = Toolset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $toolsetId)
            ->first();

        if ($toolset === null) {
            return '';
        }

        $stored = $toolset->browser_helpers ?? [];
        $helpers = $stored['helpers'] ?? [];
        if (! is_array($helpers) || $helpers === []) {
            return '';
        }

        $snippets = [];
        foreach ($helpers as $helper) {
            if (! is_array($helper) || ! ($helper['approved'] ?? false)) {
                continue;
            }
            $snippets[] = '# helper: '.($helper['name'] ?? 'unnamed')."\n".($helper['code'] ?? '');
        }

        return implode("\n\n", $snippets);
    }

    private function stagePendingHelpers(string $toolsetId, string $teamId, string $diff): void
    {
        /** @var Toolset|null $toolset */
        $toolset = Toolset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $toolsetId)
            ->first();

        if ($toolset === null) {
            return;
        }

        $stored = $toolset->browser_helpers ?? [];
        $existing = $stored['helpers'] ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }

        $existing[] = [
            'name' => 'pending_'.now()->format('Ymd_His'),
            'code' => $diff,
            'approved' => false,
            'added_by' => null,
            'added_at' => now()->toIso8601String(),
        ];

        $toolset->forceFill([
            'browser_helpers' => ['helpers' => $existing],
            'browser_helpers_pending_review' => true,
        ])->save();
    }
}
