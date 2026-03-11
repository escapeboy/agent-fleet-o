#!/usr/bin/env python3
"""
Agent Fleet — Host Agent Bridge (Python)

Lightweight HTTP bridge that runs on the host machine and proxies
agent discovery + execution requests from Docker containers.

Zero dependencies — uses only Python 3 stdlib.

Usage:
    LOCAL_AGENT_BRIDGE_SECRET=your-secret python3 docker/host-bridge.py
    LOCAL_AGENT_BRIDGE_SECRET=your-secret python3 docker/host-bridge.py --port 8065
"""

import json
import hmac
import os
import re
import subprocess
import sys
import tempfile
import time
from http.server import HTTPServer, BaseHTTPRequestHandler
from pathlib import Path

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

BRIDGE_SECRET = os.environ.get("LOCAL_AGENT_BRIDGE_SECRET", "")
BRIDGE_PORT = int(os.environ.get("LOCAL_AGENT_BRIDGE_PORT", "8065"))
CONFIG_PATH = Path(__file__).resolve().parent.parent / "config" / "local_agents.php"

# Hardcoded agent registry (mirrors config/local_agents.php)
# This avoids needing to parse PHP config from Python.
AGENTS = {
    "codex": {
        "name": "OpenAI Codex",
        "binary": "codex",
        "detect_command": "codex --version",
        "command_template": "{binary} exec --json --full-auto",
        "stdin": True,
    },
    "claude-code": {
        "name": "Claude Code",
        "binary": "claude",
        "detect_command": "claude --version",
        "command_template": "{binary} --print --output-format json --dangerously-skip-permissions",
        "stdin": True,
    },
    "cursor": {
        "name": "Cursor",
        "binary": "agent",
        "detect_command": "agent --version",
        # Prompt is injected via a temp file (Cursor does not read from stdin).
        # The {prompt_file} placeholder is replaced at execution time.
        "command_template": '{binary} -p "$(cat {prompt_file})" --output-format json --force --trust --approve-mcps',
        "stdin": False,
        # Verify the binary identifies itself as Cursor before accepting it.
        "version_check": "cursor",
    },
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def which(binary: str) -> str | None:
    """Find binary path, similar to `which`."""
    try:
        result = subprocess.run(
            ["which", binary], capture_output=True, text=True, timeout=5
        )
        if result.returncode == 0 and result.stdout.strip():
            return result.stdout.strip()
    except Exception:
        pass
    return None


def get_version(command: str) -> str | None:
    """Run a version command and extract the version string."""
    try:
        result = subprocess.run(
            command, shell=True, capture_output=True, text=True, timeout=10
        )
        if result.returncode != 0 or not result.stdout.strip():
            return None
        output = result.stdout.strip()
        match = re.search(r"v?(\d+\.\d+(?:\.\d+)?(?:[.\-]\w+)?)", output)
        if match:
            return match.group(1)
        return output.split("\n")[0]
    except Exception:
        return None


def get_raw_output(command: str) -> str | None:
    """Run a command and return its raw stdout (for identity verification)."""
    try:
        result = subprocess.run(
            command, shell=True, capture_output=True, text=True, timeout=10
        )
        if result.returncode != 0:
            return None
        return result.stdout.strip() or None
    except Exception:
        return None


def authenticate(headers: dict) -> bool:
    """Validate Bearer token."""
    if not BRIDGE_SECRET:
        return False
    auth = headers.get("Authorization", "")
    match = re.match(r"^Bearer\s+(.+)$", auth, re.IGNORECASE)
    if not match:
        return False
    return hmac.compare_digest(BRIDGE_SECRET, match.group(1))


# ---------------------------------------------------------------------------
# HTTP Handler
# ---------------------------------------------------------------------------

class BridgeHandler(BaseHTTPRequestHandler):
    """Handles bridge HTTP requests."""

    def log_message(self, format, *args):
        """Override to use cleaner logging."""
        sys.stderr.write(f"[bridge] {args[0]}\n")

    def send_json(self, data: dict, status: int = 200):
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def do_GET(self):
        path = self.path.split("?")[0]

        # --- /health (no auth) ---
        if path == "/health":
            self.send_json({
                "status": "ok",
                "python_version": sys.version.split()[0],
                "pid": os.getpid(),
            })
            return

        # --- /discover (auth required) ---
        if path == "/discover":
            if not authenticate(dict(self.headers)):
                self.send_json({"error": "Unauthorized"}, 401)
                return

            detected = {}
            for key, agent in AGENTS.items():
                binary_path = which(agent["binary"])
                if not binary_path:
                    continue
                # For agents with a version_check requirement, verify the binary
                # identifies itself accordingly (guards against name collisions).
                # Use raw output for the identity check because get_version() strips
                # the identifier prefix and returns only the numeric part (e.g. "0.48.1").
                check = agent.get("version_check")
                if check:
                    raw = get_raw_output(agent["detect_command"]) or ""
                    if check.lower() not in raw.lower():
                        continue
                version = get_version(agent["detect_command"]) or "unknown"
                detected[key] = {
                    "name": agent["name"],
                    "version": version,
                    "path": binary_path,
                }

            self.send_json({"agents": detected})
            return

        self.send_json({"error": "Not found"}, 404)

    def do_POST(self):
        path = self.path.split("?")[0]

        # --- /execute (auth required) ---
        if path == "/execute":
            if not authenticate(dict(self.headers)):
                self.send_json({"error": "Unauthorized"}, 401)
                return

            content_length = int(self.headers.get("Content-Length", 0))
            body = json.loads(self.rfile.read(content_length)) if content_length else {}

            agent_key = body.get("agent_key", "")
            prompt = body.get("prompt", "")
            model = body.get("model")
            timeout = int(body.get("timeout", 300))
            workdir = body.get("working_directory")

            if not agent_key or prompt is None:
                self.send_json({"error": "Missing agent_key or prompt"}, 400)
                return

            if agent_key not in AGENTS:
                self.send_json({"error": f"Unknown agent: {agent_key}"}, 400)
                return

            agent = AGENTS[agent_key]
            binary_path = which(agent["binary"])

            if not binary_path:
                self.send_json({
                    "success": False,
                    "error": f"Agent binary '{agent['binary']}' not found on host",
                    "exit_code": -1,
                }, 404)
                return

            template = agent["command_template"]
            if model and agent_key == "codex":
                # --model is an exec subcommand flag
                template = template + " --model " + model
            if model and model != "auto" and agent_key == "cursor":
                template = template + " --model " + model

            # Resolve working directory
            cwd = workdir if workdir and os.path.isdir(workdir) else str(CONFIG_PATH.parent.parent)

            # Cursor CLI does not read from stdin — write prompt to a temp file
            # and inject the path into the command template.
            tmp_prompt_file = None
            uses_stdin = agent.get("stdin", True)
            if not uses_stdin:
                tmp_fd, tmp_prompt_file = tempfile.mkstemp(prefix="fleetq_cursor_")
                try:
                    os.write(tmp_fd, prompt.encode())
                finally:
                    os.close(tmp_fd)
                command = template.format(binary=binary_path, prompt_file=tmp_prompt_file)
            else:
                command = template.format(binary=binary_path)

            start = time.monotonic_ns()

            try:
                proc = subprocess.run(
                    command,
                    shell=True,
                    input=prompt if uses_stdin else None,
                    capture_output=True,
                    text=True,
                    timeout=timeout,
                    cwd=cwd,
                )
            except subprocess.TimeoutExpired:
                elapsed_ms = int((time.monotonic_ns() - start) / 1_000_000)
                self.send_json({
                    "success": False,
                    "error": f"Process timed out after {timeout}s",
                    "exit_code": -1,
                    "execution_time_ms": elapsed_ms,
                }, 504)
                return
            except Exception as e:
                self.send_json({
                    "success": False,
                    "error": str(e),
                    "exit_code": -1,
                }, 500)
                return
            finally:
                # Clean up the temp prompt file used for Cursor CLI
                if tmp_prompt_file:
                    try:
                        os.unlink(tmp_prompt_file)
                    except OSError:
                        pass

            elapsed_ms = int((time.monotonic_ns() - start) / 1_000_000)

            if proc.returncode != 0:
                self.send_json({
                    "success": False,
                    "output": proc.stdout,
                    "stderr": proc.stderr,
                    "error": proc.stderr or "Process exited with non-zero code",
                    "exit_code": proc.returncode,
                    "execution_time_ms": elapsed_ms,
                })
                return

            self.send_json({
                "success": True,
                "output": proc.stdout,
                "stderr": proc.stderr,
                "exit_code": 0,
                "execution_time_ms": elapsed_ms,
            })
            return

        self.send_json({"error": "Not found"}, 404)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    port = BRIDGE_PORT

    # Simple --port argument
    if "--port" in sys.argv:
        idx = sys.argv.index("--port")
        if idx + 1 < len(sys.argv):
            port = int(sys.argv[idx + 1])

    if not BRIDGE_SECRET:
        print("ERROR: LOCAL_AGENT_BRIDGE_SECRET environment variable is required.", file=sys.stderr)
        print("Generate one with: python3 -c \"import secrets; print(secrets.token_hex(16))\"", file=sys.stderr)
        sys.exit(1)

    server = HTTPServer(("0.0.0.0", port), BridgeHandler)
    print(f"Agent Fleet host bridge listening on http://0.0.0.0:{port}")
    print(f"  /health   — liveness check")
    print(f"  /discover — list available agents")
    print(f"  /execute  — run an agent")
    print(f"Press Ctrl+C to stop.\n")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nBridge stopped.")
        server.server_close()


if __name__ == "__main__":
    main()
