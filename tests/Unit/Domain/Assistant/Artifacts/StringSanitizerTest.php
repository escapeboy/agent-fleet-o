<?php

namespace Tests\Unit\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;
use PHPUnit\Framework\TestCase;

class StringSanitizerTest extends TestCase
{
    public function test_clean_strips_tags(): void
    {
        $this->assertSame('hello world', StringSanitizer::clean('<script>hello</script> world'));
    }

    public function test_clean_returns_null_for_non_scalar(): void
    {
        $this->assertNull(StringSanitizer::clean([]));
        $this->assertNull(StringSanitizer::clean(null));
        $this->assertNull(StringSanitizer::clean(new \stdClass));
    }

    public function test_clean_returns_null_for_empty_after_strip(): void
    {
        $this->assertNull(StringSanitizer::clean('<script></script>'));
        $this->assertNull(StringSanitizer::clean('   '));
    }

    public function test_clean_caps_length(): void
    {
        $long = str_repeat('a', 500);
        $this->assertSame(200, mb_strlen(StringSanitizer::clean($long, 200) ?? ''));
    }

    public function test_clean_removes_control_chars_keeps_tab_and_newline(): void
    {
        $input = "hello\x00world\x07\ttab\nnewline";
        $result = StringSanitizer::clean($input);
        $this->assertStringNotContainsString("\x00", $result);
        $this->assertStringNotContainsString("\x07", $result);
        $this->assertStringContainsString("\t", $result);
        $this->assertStringContainsString("\n", $result);
    }

    public function test_clean_accepts_numeric_scalars(): void
    {
        $this->assertSame('42', StringSanitizer::clean(42));
        $this->assertSame('3.14', StringSanitizer::clean(3.14));
    }

    public function test_clean_or_empty_never_returns_null(): void
    {
        $this->assertSame('', StringSanitizer::cleanOrEmpty(null));
        $this->assertSame('', StringSanitizer::cleanOrEmpty([]));
        $this->assertSame('ok', StringSanitizer::cleanOrEmpty('ok'));
    }

    public function test_slugify_lowercases_and_replaces_non_alpha(): void
    {
        $this->assertSame('hello_world', StringSanitizer::slugify('Hello World!'));
        $this->assertSame('target_audience', StringSanitizer::slugify('target-audience'));
        $this->assertSame('abc_123', StringSanitizer::slugify('abc 123'));
    }

    public function test_slugify_caps_length(): void
    {
        $long = str_repeat('a', 100);
        $this->assertSame(40, mb_strlen(StringSanitizer::slugify($long, 40) ?? ''));
    }

    public function test_slugify_returns_null_for_empty(): void
    {
        $this->assertNull(StringSanitizer::slugify(''));
        $this->assertNull(StringSanitizer::slugify('!!!'));
    }

    public function test_clamp_number_respects_bounds(): void
    {
        $this->assertSame(5.0, StringSanitizer::clampNumber(10, 0, 5));
        $this->assertSame(0.0, StringSanitizer::clampNumber(-3, 0, 5));
        $this->assertSame(3.0, StringSanitizer::clampNumber(3, 0, 5));
    }

    public function test_clamp_number_returns_null_for_non_numeric(): void
    {
        $this->assertNull(StringSanitizer::clampNumber('abc'));
        $this->assertNull(StringSanitizer::clampNumber(null));
        $this->assertNull(StringSanitizer::clampNumber([]));
    }
}
