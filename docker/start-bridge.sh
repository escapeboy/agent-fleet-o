#!/usr/bin/env sh
#
# Agent Fleet — Host Bridge Launcher
#
# Automatically detects PHP or Python 3, generates a bridge secret
# if needed, and starts the host agent bridge server.
#
# Usage:
#   ./docker/start-bridge.sh          # auto-detect runtime + auto-generate secret
#   ./docker/start-bridge.sh --port 9000
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"
DEFAULT_PORT=8065
PORT="$DEFAULT_PORT"

# ---------------------------------------------------------------------------
# Parse arguments
# ---------------------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        --port)
            PORT="$2"
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Secret management — auto-generate if missing
# ---------------------------------------------------------------------------
ensure_secret() {
    if [ -n "$LOCAL_AGENT_BRIDGE_SECRET" ]; then
        return 0
    fi

    # Try to read from .env
    if [ -f "$ENV_FILE" ]; then
        existing=$(grep -E "^LOCAL_AGENT_BRIDGE_SECRET=" "$ENV_FILE" 2>/dev/null | cut -d'=' -f2- | tr -d '[:space:]')
        if [ -n "$existing" ]; then
            export LOCAL_AGENT_BRIDGE_SECRET="$existing"
            return 0
        fi
    fi

    # Generate a new secret
    echo "No LOCAL_AGENT_BRIDGE_SECRET found. Generating one..."
    if command -v python3 >/dev/null 2>&1; then
        secret=$(python3 -c "import secrets; print(secrets.token_hex(16))")
    elif command -v php >/dev/null 2>&1; then
        secret=$(php -r "echo bin2hex(random_bytes(16));")
    elif command -v openssl >/dev/null 2>&1; then
        secret=$(openssl rand -hex 16)
    else
        echo "ERROR: Cannot generate secret. Install python3, php, or openssl."
        exit 1
    fi

    export LOCAL_AGENT_BRIDGE_SECRET="$secret"

    # Write to .env
    if [ -f "$ENV_FILE" ]; then
        if grep -q "^LOCAL_AGENT_BRIDGE_SECRET=" "$ENV_FILE" 2>/dev/null; then
            # Replace existing empty value
            if command -v sed >/dev/null 2>&1; then
                sed -i.bak "s/^LOCAL_AGENT_BRIDGE_SECRET=.*/LOCAL_AGENT_BRIDGE_SECRET=$secret/" "$ENV_FILE"
                rm -f "$ENV_FILE.bak"
            fi
        else
            # Append
            printf "\nLOCAL_AGENT_BRIDGE_SECRET=%s\n" "$secret" >> "$ENV_FILE"
        fi
        echo "Secret saved to .env"
    else
        echo "WARNING: No .env file found. Export manually:"
        echo "  export LOCAL_AGENT_BRIDGE_SECRET=$secret"
    fi

    echo ""
}

# ---------------------------------------------------------------------------
# Runtime detection — prefer PHP, fall back to Python 3
# ---------------------------------------------------------------------------
detect_runtime() {
    if command -v php >/dev/null 2>&1; then
        php_version=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null || echo "0.0")
        major=$(echo "$php_version" | cut -d. -f1)
        if [ "$major" -ge 8 ]; then
            echo "php"
            return
        fi
    fi

    if command -v python3 >/dev/null 2>&1; then
        echo "python3"
        return
    fi

    echo "none"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

echo "=== Agent Fleet — Host Bridge ==="
echo ""

ensure_secret

runtime=$(detect_runtime)

case "$runtime" in
    php)
        echo "Runtime: PHP $(php -r 'echo PHP_VERSION;')"
        echo "Listening on: http://0.0.0.0:$PORT"
        echo ""
        exec php -S "0.0.0.0:$PORT" "$SCRIPT_DIR/host-bridge.php"
        ;;
    python3)
        echo "Runtime: Python $(python3 --version 2>&1 | cut -d' ' -f2)"
        echo ""
        export LOCAL_AGENT_BRIDGE_PORT="$PORT"
        exec python3 "$SCRIPT_DIR/host-bridge.py" --port "$PORT"
        ;;
    *)
        echo "ERROR: Neither PHP 8+ nor Python 3 found on this system."
        echo ""
        echo "Install one of:"
        echo "  - PHP 8.0+:   brew install php        (macOS)"
        echo "  - Python 3:   brew install python3     (macOS)"
        echo "                 sudo apt install python3 (Ubuntu/Debian)"
        exit 1
        ;;
esac
