<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\DTOs\CommandValidationResult;

class CommandSecurityPolicy
{
    /** Commands always blocked regardless of any configuration. */
    private const ALWAYS_BLOCKED = [
        'rm -rf /',
        'mkfs',
        'dd',
        'shutdown',
        'reboot',
        'iptables',
        'ufw',
        'passwd',
        'useradd',
        'userdel',
        'systemctl',
    ];

    /** Sensitive paths blocked for file access. */
    private const SENSITIVE_PATHS = [
        '~/.ssh',
        '~/.aws',
        '~/.kube',
        '~/.gnupg',
        '~/.docker',
        '/etc/shadow',
        '/etc/passwd',
    ];

    /** Dangerous shell patterns always blocked. */
    private const DANGEROUS_PATTERNS = [
        '| bash',
        '| sh',
        '| zsh',
        '$(',
        '`',
        '&& rm',
        '; rm',
    ];

    /** Commands that require approval / audit logging. */
    private const REQUIRES_APPROVAL = [
        'sudo',
        'su',
        'pkill',
        'kill',
        'chmod',
        'chown',
    ];

    /**
     * Validate a command against the security hierarchy.
     *
     * Hierarchy (most restrictive wins):
     * 1. Platform-level: always-blocked commands and patterns
     * 2. Tool-level: transport_config['allowed_commands']
     * 3. Project-level: settings['command_policy'] (restrict only)
     * 4. Agent-level: pivot overrides['command_policy'] (restrict only)
     */
    public function validate(
        string $command,
        ?string $workingDirectory,
        array $toolAllowedCommands,
        array $toolAllowedPaths = [],
        ?array $projectCommandPolicy = null,
        ?array $agentCommandPolicy = null,
    ): CommandValidationResult {
        $binary = basename(explode(' ', trim($command))[0]);

        // 1. Platform-level: always-blocked commands
        foreach (self::ALWAYS_BLOCKED as $blocked) {
            if (stripos($command, $blocked) !== false) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Command contains platform-blocked pattern: '{$blocked}'",
                    level: 'platform',
                );
            }
        }

        // 2. Platform-level: dangerous shell patterns
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (stripos($command, $pattern) !== false) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Command contains dangerous pattern: '{$pattern}'",
                    level: 'platform',
                );
            }
        }

        // 3. Platform-level: sensitive path access
        foreach (self::SENSITIVE_PATHS as $path) {
            $expanded = str_replace('~', getenv('HOME') ?: '/root', $path);
            if (stripos($command, $path) !== false || stripos($command, $expanded) !== false) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Command accesses sensitive path: '{$path}'",
                    level: 'platform',
                );
            }
        }

        // 4. Tool-level: allowed commands whitelist
        if (! empty($toolAllowedCommands) && ! in_array($binary, $toolAllowedCommands)) {
            return new CommandValidationResult(
                allowed: false,
                reason: "Command '{$binary}' is not in tool allowlist",
                level: 'tool',
            );
        }

        // 5. Tool-level: path validation
        if (! empty($toolAllowedPaths) && $workingDirectory) {
            $resolvedCwd = realpath($workingDirectory) ?: $workingDirectory;
            $pathAllowed = false;
            foreach ($toolAllowedPaths as $path) {
                $resolvedPath = realpath($path) ?: $path;
                if (str_starts_with($resolvedCwd, $resolvedPath)) {
                    $pathAllowed = true;
                    break;
                }
            }
            if (! $pathAllowed) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Working directory '{$workingDirectory}' is outside allowed paths",
                    level: 'tool',
                );
            }
        }

        // 6. Project-level: additional restrictions (can only restrict, not expand)
        if ($projectCommandPolicy) {
            $result = $this->applyPolicy($command, $binary, $workingDirectory, $projectCommandPolicy, 'project');
            if ($result !== null) {
                return $result;
            }
        }

        // 7. Agent-level: additional restrictions (can only restrict, not expand)
        if ($agentCommandPolicy) {
            $result = $this->applyPolicy($command, $binary, $workingDirectory, $agentCommandPolicy, 'agent');
            if ($result !== null) {
                return $result;
            }
        }

        // Check if command requires approval
        $requiresApproval = in_array($binary, self::REQUIRES_APPROVAL);

        return new CommandValidationResult(
            allowed: true,
            reason: 'Command allowed',
            level: 'tool',
            requiresApproval: $requiresApproval,
        );
    }

    /**
     * Apply a command policy (from project or agent level).
     * Returns a denial result if blocked, null if allowed to pass through.
     */
    private function applyPolicy(
        string $command,
        string $binary,
        ?string $workingDirectory,
        array $policy,
        string $level,
    ): ?CommandValidationResult {
        // Blocked commands
        $blockedCommands = $policy['blocked_commands'] ?? [];
        if (in_array($binary, $blockedCommands)) {
            return new CommandValidationResult(
                allowed: false,
                reason: "Command '{$binary}' is blocked by {$level} policy",
                level: $level,
            );
        }

        // Blocked patterns
        $blockedPatterns = $policy['blocked_patterns'] ?? [];
        foreach ($blockedPatterns as $pattern) {
            if (stripos($command, $pattern) !== false) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Command matches blocked pattern '{$pattern}' in {$level} policy",
                    level: $level,
                );
            }
        }

        // Allowed paths restriction (further restricts, not expands)
        $allowedPaths = $policy['allowed_paths'] ?? [];
        if (! empty($allowedPaths) && $workingDirectory) {
            $resolvedCwd = realpath($workingDirectory) ?: $workingDirectory;
            $pathAllowed = false;
            foreach ($allowedPaths as $path) {
                $resolvedPath = realpath($path) ?: $path;
                if (str_starts_with($resolvedCwd, $resolvedPath)) {
                    $pathAllowed = true;
                    break;
                }
            }
            if (! $pathAllowed) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Working directory restricted by {$level} policy",
                    level: $level,
                );
            }
        }

        return null;
    }
}
