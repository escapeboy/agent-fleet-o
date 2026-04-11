<?php

namespace Tests\Unit\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\UrlValidator;
use PHPUnit\Framework\TestCase;

class UrlValidatorTest extends TestCase
{
    /** @dataProvider safeUrls */
    public function test_accepts_safe_url(string $url): void
    {
        $this->assertTrue(UrlValidator::isSafe($url), "expected safe: {$url}");
    }

    /** @dataProvider unsafeUrls */
    public function test_rejects_unsafe_url(string $url): void
    {
        $this->assertFalse(UrlValidator::isSafe($url), "expected unsafe: {$url}");
    }

    public static function safeUrls(): array
    {
        return [
            ['https://fleetq.net/'],
            ['https://fleetq.net/projects/abc'],
            ['http://example.com'],
            ['https://docs.anthropic.com/en/docs/claude-code'],
            ['/projects/019d77b4-abc'],
            ['/dashboard'],
        ];
    }

    public static function unsafeUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JavaScript:alert(1)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['vbscript:msgbox("hi")'],
            ['file:///etc/passwd'],
            ['blob:http://example.com/uuid'],
            ['ftp://example.com/file'],
            ['https://user:pass@evil.com/'],
            ['https://evil.com@good.com/'],
            ['//protocol-relative.com/'],
            ['/\\protocol-hijack'],
            ['/../../../etc/passwd'],
            ['https://example.com/path?query'."\n".'injected'],
            ['https://example.com/'.str_repeat('a', 3000)],
            [''],
        ];
    }

    public function test_rejects_non_string(): void
    {
        $this->assertFalse(UrlValidator::isSafe(null));
        $this->assertFalse(UrlValidator::isSafe(['url']));
        $this->assertFalse(UrlValidator::isSafe(42));
    }

    public function test_normalize_returns_null_for_unsafe(): void
    {
        $this->assertNull(UrlValidator::normalize('javascript:alert(1)'));
    }

    public function test_normalize_returns_trimmed_url_for_safe(): void
    {
        $this->assertSame('https://fleetq.net/', UrlValidator::normalize('  https://fleetq.net/  '));
    }
}
