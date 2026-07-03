<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Services\GitCredentialHelper;
use Tests\TestCase;

class GitCredentialHelperTest extends TestCase
{
    public function test_no_token_disables_prompts_and_carries_no_secret(): void
    {
        [$env, $cleanup] = (new GitCredentialHelper)->prepare(null);

        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
        $this->assertArrayNotHasKey('GIT_ASKPASS', $env);
        $this->assertArrayNotHasKey('WARM_GIT_TOKEN', $env);
        $cleanup();
    }

    public function test_token_supplied_via_askpass_script_without_writing_the_secret(): void
    {
        $secret = 'ghp_SUPERSECRET_'.bin2hex(random_bytes(4));
        [$env, $cleanup] = (new GitCredentialHelper)->prepare($secret);

        try {
            // The token is passed to the git process env only — never in the URL.
            $this->assertSame($secret, $env['WARM_GIT_TOKEN']);
            $this->assertArrayHasKey('GIT_ASKPASS', $env);
            $this->assertFileExists($env['GIT_ASKPASS']);

            // Critically: the askpass SCRIPT ITSELF contains no secret — it only
            // echoes the env var, so the secret never lands on disk.
            $script = file_get_contents($env['GIT_ASKPASS']);
            $this->assertStringNotContainsString($secret, $script);
            $this->assertStringContainsString('WARM_GIT_TOKEN', $script);
            $this->assertStringContainsString('x-access-token', $script);
        } finally {
            $cleanup();
        }

        // cleanup() removes the script.
        $this->assertFileDoesNotExist($env['GIT_ASKPASS']);
    }
}
