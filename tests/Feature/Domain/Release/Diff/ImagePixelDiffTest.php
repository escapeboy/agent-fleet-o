<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Release\Diff;

use App\Domain\Release\Services\Diff\ImagePixelDiff;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class ImagePixelDiffTest extends TestCase
{
    private ImagePixelDiff $diff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diff = new ImagePixelDiff;
    }

    private function makePng(int $width, int $height, string $color = '#ffffff'): string
    {
        $manager = new ImageManager(GdDriver::class);
        $img = $manager->createImage($width, $height);

        return (string) $img->fill($color)->encodeUsingFileExtension('png');
    }

    public function test_identical_images_return_zero_diff(): void
    {
        $img = $this->makePng(64, 64, '#ff0000');
        $segments = $this->diff->diff($img, $img);

        $this->assertCount(1, $segments);
        $this->assertSame('image', $segments[0]['type']);
        $this->assertSame('identical', $segments[0]['status']);
        $this->assertSame(0.0, $segments[0]['diff_pct']);
    }

    public function test_different_colors_produce_nonzero_diff(): void
    {
        $left = $this->makePng(32, 32, '#000000');
        $right = $this->makePng(32, 32, '#ffffff');

        $segments = $this->diff->diff($left, $right);

        $this->assertSame('different', $segments[0]['status']);
        $this->assertGreaterThan(0.0, $segments[0]['diff_pct']);
    }

    public function test_dimension_mismatch_returns_incompatible(): void
    {
        $left = $this->makePng(32, 32);
        $right = $this->makePng(64, 64);

        $segments = $this->diff->diff($left, $right);

        $this->assertSame('incompatible', $segments[0]['status']);
        $this->assertSame([32, 32], $segments[0]['left_size']);
        $this->assertSame([64, 64], $segments[0]['right_size']);
    }

    public function test_invalid_image_data_returns_unsupported(): void
    {
        $segments = $this->diff->diff('not an image', 'also not an image');

        $this->assertSame('unsupported', $segments[0]['status']);
    }

    public function test_supports_image_mime_types(): void
    {
        $this->assertTrue($this->diff->supports('image/png'));
        $this->assertTrue($this->diff->supports('image/jpeg'));
        $this->assertTrue($this->diff->supports('image/webp'));
        $this->assertFalse($this->diff->supports('text/plain'));
        $this->assertFalse($this->diff->supports('application/json'));
    }
}
