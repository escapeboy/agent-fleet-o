# Warm-build sandbox image — the writable-hardened execution environment for
# building UNTRUSTED external repos. Run ONLY via App\Domain\Agent\Services\
# WarmBuildSandbox, which applies: --user, --cap-drop ALL, --security-opt
# no-new-privileges, --pids-limit, --memory/--cpus, --read-only + --tmpfs,
# workspace-only writable mount, and (Phase 2) an egress-allowlisted network.
#
# This image intentionally contains NO secrets and NO platform code — only the
# toolchain needed to build a checked-out repo. It must run as non-root.
FROM node:20-alpine

# Build toolchains a typical repo's build+test needs. git for the worktree;
# python3/py3-pip for python repos; no compilers-as-root tricks.
RUN apk add --no-cache \
    git \
    python3 \
    py3-pip \
    bash \
    coreutils

# The agent CLI that drives the fix (claude-code). Pinned; runs headless.
# The OAuth token is injected at run time via an explicit -e (never baked).
RUN npm install -g @anthropic-ai/claude-code@latest \
 && rm -rf /root/.npm /tmp/*

# Non-root by default. WarmBuildSandbox also forces --user 65534:65534, but a
# non-root image default is defense-in-depth if it is ever run without the flag.
# HOME points at /tmp because the root FS is mounted read-only at run time and
# only /tmp (tmpfs) + /workspace (bind) are writable.
ENV HOME=/tmp \
    NPM_CONFIG_CACHE=/tmp/.npm \
    COMPOSER_HOME=/tmp/.composer \
    XDG_CONFIG_HOME=/tmp/.config
USER 65534:65534
WORKDIR /workspace
