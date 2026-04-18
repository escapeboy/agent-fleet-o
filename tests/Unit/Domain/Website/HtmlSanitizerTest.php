<?php

namespace Tests\Unit\Domain\Website;

use App\Domain\Website\Services\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // HTMLPurifier emits E_USER_WARNING for CSS properties it doesn't natively
        // know (border-radius, box-shadow, flex, grid, transform, etc.). The sanitizer
        // silently drops them in production, but PHPUnit promotes the warning to an
        // exception. Suppress the specific noise so style-related tests can assert
        // the actual stripping behavior.
        set_error_handler(
            static fn ($severity, $message) => str_contains($message, 'Style attribute') && str_contains($message, 'is not supported'),
            E_USER_WARNING,
        );
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        parent::tearDown();
    }

    public function test_strips_script_tags(): void
    {
        $html = '<p>Hello</p><script>alert("xss")</script>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('<p>Hello</p>', $result);
    }

    public function test_strips_inline_onclick_handler(): void
    {
        $html = '<button type="button" onclick="alert(1)">Click</button>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('Click', $result);
    }

    public function test_strips_onload_on_img(): void
    {
        $html = '<img src="https://example.com/x.png" onload="alert(1)" alt="x">';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringNotContainsString('onload', $result);
        $this->assertStringContainsString('<img', $result);
    }

    public function test_strips_javascript_url_in_href(): void
    {
        $html = '<a href="javascript:alert(1)">Click</a>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_preserves_safe_html5_semantic_tags(): void
    {
        $html = '<article><header><h1>Title</h1></header><section><p>Body</p></section><footer>Foot</footer></article>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringContainsString('<article', $result);
        $this->assertStringContainsString('<header', $result);
        $this->assertStringContainsString('<section', $result);
        $this->assertStringContainsString('<footer', $result);
    }

    public function test_preserves_nav_and_main(): void
    {
        $html = '<nav><a href="https://example.com">Home</a></nav><main><p>Content</p></main>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringContainsString('<nav', $result);
        $this->assertStringContainsString('<main', $result);
        $this->assertStringContainsString('<a href="https://example.com"', $result);
    }

    public function test_allows_form_without_action(): void
    {
        $html = '<form method="post"><input type="text" name="q"><button type="submit">Go</button></form>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringContainsString('<form', $result);
        $this->assertStringContainsString('<input', $result);
        $this->assertStringContainsString('<button', $result);
    }

    public function test_allows_form_with_action(): void
    {
        $html = '<form method="post" action="/api/public/sites/foo/forms/bar"><input name="email"></form>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringContainsString('action="/api/public/sites/foo/forms/bar"', $result);
        $this->assertStringContainsString('method="post"', $result);
    }

    public function test_preserves_safe_inline_styles(): void
    {
        $html = '<div style="color: red; padding: 10px; font-weight: bold;">Box</div>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringContainsString('color:', $result);
        $this->assertStringContainsString('padding:', $result);
        $this->assertStringContainsString('font-weight:', $result);
    }

    public function test_strips_disallowed_style_properties(): void
    {
        $html = '<div style="color: red; behavior: url(xss.htc);">Box</div>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringNotContainsString('behavior', $result);
        $this->assertStringContainsString('color', $result);
    }

    public function test_handles_empty_input(): void
    {
        $this->assertSame('', HtmlSanitizer::purify(''));
    }

    public function test_handles_malformed_html(): void
    {
        $html = '<p>Unclosed <strong>bold<div>nested</p>';
        $result = HtmlSanitizer::purify($html);

        // Should produce valid HTML without crashing
        $this->assertIsString($result);
        $this->assertStringContainsString('Unclosed', $result);
    }

    public function test_strips_iframe(): void
    {
        $html = '<p>Before</p><iframe src="https://evil.example"></iframe><p>After</p>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
    }

    public function test_allows_https_images(): void
    {
        $html = '<img src="https://cdn.example.com/img.png" alt="test" width="100" height="50">';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringContainsString('src="https://cdn.example.com/img.png"', $result);
        $this->assertStringContainsString('alt="test"', $result);
    }

    public function test_preserves_label_for_attribute(): void
    {
        $html = '<form><label for="email">Email</label><input type="email" id="email" name="email"></form>';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringContainsString('for="email"', $result);
        $this->assertStringContainsString('<label', $result);
    }

    public function test_strips_data_uri_in_img_src(): void
    {
        $html = '<img src="data:image/svg+xml,<svg onload=alert(1)>" alt="x">';
        $result = HtmlSanitizer::purify($html);

        $this->assertStringNotContainsString('data:', $result);
        $this->assertStringNotContainsString('onload', $result);
    }
}
