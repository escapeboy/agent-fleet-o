<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Release\Diff;

use App\Domain\Release\Services\Diff\DiffStrategyResolver;
use App\Domain\Release\Services\Diff\ImagePixelDiff;
use App\Domain\Release\Services\Diff\JsonStructuralDiff;
use App\Domain\Release\Services\Diff\TextLineDiff;
use Tests\TestCase;

class DiffStrategyResolverTest extends TestCase
{
    private function resolver(): DiffStrategyResolver
    {
        return app(DiffStrategyResolver::class);
    }

    public function test_resolves_json_for_application_json(): void
    {
        $strategy = $this->resolver()->resolve('application/json');
        $this->assertInstanceOf(JsonStructuralDiff::class, $strategy);
    }

    public function test_resolves_image_for_png_mime(): void
    {
        $strategy = $this->resolver()->resolve('image/png');
        $this->assertInstanceOf(ImagePixelDiff::class, $strategy);
    }

    public function test_resolves_text_as_default(): void
    {
        $strategy = $this->resolver()->resolve('text/plain');
        $this->assertInstanceOf(TextLineDiff::class, $strategy);
    }

    public function test_sniffs_png_magic_bytes(): void
    {
        $pngHeader = "\x89PNG\r\n\x1a\nfake-data";
        $strategy = $this->resolver()->resolve(null, $pngHeader);
        $this->assertInstanceOf(ImagePixelDiff::class, $strategy);
    }

    public function test_sniffs_jpeg_magic_bytes(): void
    {
        $jpegHeader = "\xff\xd8\xfffake-data";
        $strategy = $this->resolver()->resolve(null, $jpegHeader);
        $this->assertInstanceOf(ImagePixelDiff::class, $strategy);
    }

    public function test_sniffs_json_by_brace(): void
    {
        $strategy = $this->resolver()->resolve(null, '   {"a":1}');
        $this->assertInstanceOf(JsonStructuralDiff::class, $strategy);
    }

    public function test_sniffs_json_by_array_bracket(): void
    {
        $strategy = $this->resolver()->resolve(null, '[1,2,3]');
        $this->assertInstanceOf(JsonStructuralDiff::class, $strategy);
    }

    public function test_falls_back_to_text_when_no_signal(): void
    {
        $strategy = $this->resolver()->resolve(null, null);
        $this->assertInstanceOf(TextLineDiff::class, $strategy);
    }
}
