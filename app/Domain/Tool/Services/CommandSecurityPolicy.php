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
     * 2. Organization-level: team-wide security policy (GlobalSettings)
     * 3. Tool-level: transport_config['allowed_commands']
     * 4. Project-level: settings['command_policy'] (restrict only)
     * 5. Agent-level: pivot overrides['command_policy'] (restrict only)
     */
    public function validate(
        string $command,
        ?string $workingDirectory,
        array $toolAllowedCommands,
        array $toolAllowedPaths = [],
        ?array $projectCommandPolicy = null,
        ?array $agentCommandPolicy = null,
        ?array $orgSecurityPolicy = null,
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

        // 4. Organization-level: team-wide security policy
        if ($orgSecurityPolicy) {
            $result = $this->applyOrgPolicy($command, $binary, $workingDirectory, $orgSecurityPolicy);
            if ($result !== null) {
                return $result;
            }
        }

        // 5. Tool-level: allowed commands whitelist
        if (! empty($toolAllowedCommands) && ! in_array($binary, $toolAllowedCommands)) {
            return new CommandValidationResult(
                allowed: false,
                reason: "Command '{$binary}' is not in tool allowlist",
                level: 'tool',
            );
        }

        // 6. Tool-level: path validation
        if (! empty($toolAllowedPaths) && $workingDirectory) {
            if (! $this->isPathAllowed($workingDirectory, $toolAllowedPaths)) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Working directory '{$workingDirectory}' is outside allowed paths",
                    level: 'tool',
                );
            }
        }

        // 7. Project-level: additional restrictions (can only restrict, not expand)
        if ($projectCommandPolicy) {
            $result = $this->applyPolicy($command, $binary, $workingDirectory, $projectCommandPolicy, 'project');
            if ($result !== null) {
                return $result;
            }
        }

        // 8. Agent-level: additional restrictions (can only restrict, not expand)
        if ($agentCommandPolicy) {
            $result = $this->applyPolicy($command, $binary, $workingDirectory, $agentCommandPolicy, 'agent');
            if ($result !== null) {
                return $result;
            }
        }

        // Check if command requires approval (platform + org level)
        $requiresApproval = in_array($binary, self::REQUIRES_APPROVAL);
        if (! $requiresApproval && $orgSecurityPolicy) {
            $orgApproval = $orgSecurityPolicy['require_approval_for'] ?? [];
            foreach ($orgApproval as $pattern) {
                if (stripos($command, $pattern) !== false) {
                    $requiresApproval = true;
                    break;
                }
            }
        }

        return new CommandValidationResult(
            allowed: true,
            reason: 'Command allowed',
            level: 'tool',
            requiresApproval: $requiresApproval,
        );
    }

    /**
     * Apply the organization-level security policy.
     * Organization policy sits between Platform and Tool levels.
     * It can block commands and restrict paths.
     * If it has an allowed_commands list, only those commands pass through.
     */
    private function applyOrgPolicy(
        string $command,
        string $binary,
        ?string $workingDirectory,
        array $policy,
    ): ?CommandValidationResult {
        // Org blocked commands
        $blockedCommands = $policy['blocked_commands'] ?? [];
        if (in_array($binary, $blockedCommands)) {
            return new CommandValidationResult(
                allowed: false,
                reason: "Command '{$binary}' is blocked by organization policy",
                level: 'organization',
            );
        }

        // Org blocked patterns
        $blockedPatterns = $policy['blocked_patterns'] ?? [];
        foreach ($blockedPatterns as $pattern) {
            if (stripos($command, $pattern) !== false) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: "Command matches blocked pattern '{$pattern}' in organization policy",
                    level: 'organization',
                );
            }
        }

        // Org allowed commands (whitelist — if set, only these pass)
        $allowedCommands = $policy['allowed_commands'] ?? [];
        if (! empty($allowedCommands) && ! in_array($binary, $allowedCommands)) {
            return new CommandValidationResult(
                allowed: false,
                reason: "Command '{$binary}' is not in organization allowlist",
                level: 'organization',
            );
        }

        // Org allowed paths restriction
        $allowedPaths = $policy['allowed_paths'] ?? [];
        if (! empty($allowedPaths) && $workingDirectory) {
            if (! $this->isPathAllowed($workingDirectory, $allowedPaths)) {
                return new CommandValidationResult(
                    allowed: false,
                    reason: 'Working directory restricted by organization policy',
                    level: 'organization',
                );
            }
        }

        // Org max command timeout
        $maxTimeout = $policy['max_command_timeout'] ?? null;
        if ($maxTimeout !== null) {
            // Timeout is enforced at execution time, not here.
            // Store it for downstream use.
        }

        return null;
    }

    /**
     * Check if a working directory is within any of the allowed paths.
     * Handles symlinks correctly by comparing both raw and resolved paths.
     */
    private function isPathAllowed(string $workingDirectory, array $allowedPaths): bool
    {
        $cwdVariants = array_unique(array_filter([
            $workingDirectory,
            realpath($workingDirectory),
        ]));

        foreach ($allowedPaths as $path) {
            $pathVariants = array_unique(array_filter([
                $path,
                realpath($path),
            ]));

            foreach ($cwdVariants as $cwd) {
                foreach ($pathVariants as $allowed) {
                    if (str_starts_with($cwd, $allowed)) {
                        return true;
                    }
                }
            }
        }

        return false;
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
            if (! $this->isPathAllowed($workingDirectory, $allowedPaths)) {
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
