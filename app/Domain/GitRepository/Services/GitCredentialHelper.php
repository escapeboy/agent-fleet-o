<?php

namespace App\Domain\GitRepository\Services;

/**
 * Supplies a tenant's git token to a git subprocess WITHOUT ever:
 *   - embedding it in the remote URL (so it never lands in .git/config), or
 *   - exporting it into the agent/sandbox environment, or
 *   - persisting it to a credential store.
 *
 * It writes a tiny GIT_ASKPASS script (which contains NO secret — it only echoes
 * an env var) and returns the env that must be applied to the git Process ONLY.
 * The token lives in that one subprocess's environment for the duration of the
 * git call and nowhere else. Call cleanup() in a finally to remove the script.
 *
 * Usage:
 *   [$env, $cleanup] = $helper->prepare($token);
 *   try { Process::env($env)->run(['git','clone', $cleanHttpsUrl, $dst]); }
 *   finally { $cleanup(); }
 */
class GitCredentialHelper
{
    /**
     * @return array{0: array<string,string>, 1: callable():void}
     *                                                            [env to apply to the git Process, cleanup closure]
     */
    public function prepare(?string $token): array
    {
        if ($token === null || $token === '') {
            // Public repo / no auth — still disable interactive prompts so a
            // private repo fails fast instead of hanging on a TTY.
            return [['GIT_TERMINAL_PROMPT' => '0'], function (): void {}];
        }

        $script = tempnam(sys_get_temp_dir(), 'git-askpass-').'.sh';
        // The script echoes an env var; the SECRET is never written to disk. Git
        // calls askpass with a prompt like "Username for '...'" / "Password ...".
        file_put_contents(
            $script,
            "#!/bin/sh\ncase \"\$1\" in *sername*) echo x-access-token;; *) echo \"\$WARM_GIT_TOKEN\";; esac\n",
        );
        @chmod($script, 0700);

        $env = [
            'GIT_ASKPASS' => $script,
            'WARM_GIT_TOKEN' => $token,
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        $cleanup = function () use ($script): void {
            if (is_file($script)) {
                @unlink($script);
            }
        };

        return [$env, $cleanup];
    }
}
