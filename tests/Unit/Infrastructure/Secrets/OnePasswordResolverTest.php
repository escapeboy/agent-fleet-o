<?php

namespace Tests\Unit\Infrastructure\Secrets;

use App\Infrastructure\Secrets\OnePasswordResolver;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class OnePasswordResolverTest extends TestCase
{
    private const VALID_TOKEN = 'ops_eyJzaWduSW5BZGRyZXNzIjoidGVzdC4xcGFzc3dvcmQuY29tIn0';

    private OnePasswordResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new OnePasswordResolver;
    }

    public function test_resolves_a_valid_reference_via_op_cli(): void
    {
        Process::fake(fn () => new FakeProcessResult(output: 'sk_live_abc123', exitCode: 0));

        $value = $this->resolver->resolve('op://Engineering/Stripe/credential', self::VALID_TOKEN);

        $this->assertSame('sk_live_abc123', $value);
        Process::assertRan(function ($process) {
            // The token must be passed via env, never argv (so it's not visible in `ps`).
            return is_array($process->command)
                && $process->command === ['op', 'read', '--no-newline', 'op://Engineering/Stripe/credential']
                && ($process->environment['OP_SERVICE_ACCOUNT_TOKEN'] ?? null) === self::VALID_TOKEN;
        });
    }

    public function test_rejects_malformed_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid 1Password secret reference');

        $this->resolver->resolve('not-a-valid-ref', self::VALID_TOKEN);
    }

    public function test_rejects_path_traversal_in_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Forward slashes inside a segment break the 3-segment grammar.
        $this->resolver->resolve('op://Eng/../OtherVault/item/field', self::VALID_TOKEN);
    }

    public function test_rejects_shell_metacharacters_in_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->resolve('op://Eng/item$(rm -rf)/field', self::VALID_TOKEN);
    }

    public function test_rejects_token_without_ops_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with "ops_"');

        $this->resolver->resolve('op://Eng/Stripe/credential', 'not_a_real_token_'.str_repeat('x', 30));
    }

    public function test_rejects_short_token(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve('op://Eng/Stripe/credential', 'ops_short');
    }

    public function test_surfaces_op_cli_failure_with_stderr(): void
    {
        Process::fake(fn () => new FakeProcessResult(
            output: '',
            errorOutput: 'authentication required: invalid service account token',
            exitCode: 1,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('op read failed (exit 1): authentication required');

        $this->resolver->resolve('op://Eng/Stripe/credential', self::VALID_TOKEN);
    }

    public function test_resolves_many_makes_one_call_per_reference(): void
    {
        $count = 0;
        Process::fake(function ($process) use (&$count) {
            $count++;

            return new FakeProcessResult(output: "value-{$count}", exitCode: 0);
        });

        $references = [
            'op://Eng/Stripe/credential',
            'op://Eng/Twilio/auth_token',
        ];

        $resolved = $this->resolver->resolveMany($references, self::VALID_TOKEN);

        $this->assertCount(2, $resolved);
        $this->assertSame('value-1', $resolved['op://Eng/Stripe/credential']);
        $this->assertSame('value-2', $resolved['op://Eng/Twilio/auth_token']);
        $this->assertSame(2, $count);
    }

    public function test_resolves_many_returns_empty_for_empty_input_without_invoking_op(): void
    {
        Process::fake();

        $resolved = $this->resolver->resolveMany([], self::VALID_TOKEN);

        $this->assertSame([], $resolved);
        Process::assertNothingRan();
    }
}
