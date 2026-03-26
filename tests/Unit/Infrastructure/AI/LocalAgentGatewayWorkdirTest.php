<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unit tests for the working-directory resolution logic in LocalAgentGateway.
 *
 * resolveWorkingDirectory() must:
 *  - Return base_path() when the supplied value is null or empty
 *  - Return the real (canonicalised) path when the supplied directory exists
 *  - Fall back to base_path() when the supplied path does not exist
 *  - Prevent path traversal by canonicalising with realpath()
 */
class LocalAgentGatewayWorkdirTest extends TestCase
{
    private LocalAgentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $this->gateway = new LocalAgentGateway($discovery);
    }

    private function resolve(?string $path): string
    {
        $ref = new ReflectionMethod($this->gateway, 'resolveWorkingDirectory');
        $ref->setAccessible(true);

        return $ref->invoke($this->gateway, $path);
    }

    public function test_null_resolves_to_base_path(): void
    {
        $this->assertSame(base_path(), $this->resolve(null));
    }

    public function test_empty_string_resolves_to_base_path(): void
    {
        $this->assertSame(base_path(), $this->resolve(''));
    }

    public function test_nonexistent_path_falls_back_to_base_path(): void
    {
        $this->assertSame(base_path(), $this->resolve('/this/path/does/not/exist/ever'));
    }

    public function test_valid_directory_returns_real_path(): void
    {
        $tmp = sys_get_temp_dir();
        $result = $this->resolve($tmp);

        // realpath() on /tmp on macOS resolves to /private/tmp
        $this->assertSame(realpath($tmp), $result);
        $this->assertTrue(is_dir($result));
    }

    public function test_traversal_sequence_in_existing_path_is_canonicalised(): void
    {
        // e.g. /tmp/../../etc would resolve via realpath to /etc on Linux
        // We just verify the returned value equals realpath() of the input
        $path = sys_get_temp_dir().'/../../'.basename(sys_get_temp_dir());
        $real = realpath($path);

        if ($real !== false && is_dir($real)) {
            $this->assertSame($real, $this->resolve($path));
        } else {
            // If the composed path doesn't resolve to a real dir, expect fallback
            $this->assertSame(base_path(), $this->resolve($path));
        }
    }

    public function test_file_path_falls_back_to_base_path(): void
    {
        // A file (not a directory) should be rejected
        $file = tempnam(sys_get_temp_dir(), 'wdtest_');
        $this->assertSame(base_path(), $this->resolve($file));
        @unlink($file);
    }
}
