# Agent Fleet — Code Execution Sandbox Image
# Minimal environment for running agent-generated scripts in isolation.
#
# Security hardening is applied at the container runtime level (not here):
#   --network none       : no outbound network
#   --cap-drop ALL       : no Linux capabilities
#   --read-only          : root filesystem is read-only
#   --memory 512m        : memory hard limit
#   --cpus 1             : CPU hard limit
#
# Build: docker build -t agent-fleet/sandbox:latest -f docker/sandbox/Dockerfile .
# The /workspace directory is mounted read-only from the worktree by the host.

FROM php:8.4-cli-alpine

# Install common runtimes for multi-language support
RUN apk add --no-cache \
    git \
    nodejs \
    npm \
    python3 \
    bash \
    curl \
    # Composer for PHP projects
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /workspace

# Drop to nobody to avoid running as root
USER nobody
