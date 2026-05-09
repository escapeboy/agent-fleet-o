<?php

declare(strict_types=1);

namespace App\Domain\Release\Services\Diff;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Pixel-by-pixel image diff. Decodes both sides via Intervention Image v4,
 * computes a coarse change percentage by comparing dimensions and a sampled
 * pixel grid. Returns a single result segment.
 *
 * MVP scope:
 *  - Same-dimension comparison only; mismatched dimensions return "incompatible"
 *  - 8x8 sampling grid (64 pixels) for fast approximation; can be tightened later
 *  - No overlay generation in this iteration (deferred — UI shows pct only)
 */
class ImagePixelDiff implements DiffStrategyInterface
{
    public function diff(?string $left, ?string $right, array $context = []): array
    {
        if ($left === null || $right === null) {
            return [['type' => 'image', 'status' => 'unsupported', 'reason' => 'missing image content']];
        }

        try {
            $manager = new ImageManager(GdDriver::class);
            $leftImg = $manager->decode($left);
            $rightImg = $manager->decode($right);
        } catch (\Throwable $e) {
            return [['type' => 'image', 'status' => 'unsupported', 'reason' => 'image decode failed: '.$e->getMessage()]];
        }

        $leftSize = [$leftImg->width(), $leftImg->height()];
        $rightSize = [$rightImg->width(), $rightImg->height()];

        if ($leftSize !== $rightSize) {
            return [[
                'type' => 'image',
                'status' => 'incompatible',
                'reason' => "dimensions differ: {$leftSize[0]}x{$leftSize[1]} vs {$rightSize[0]}x{$rightSize[1]}",
                'left_size' => $leftSize,
                'right_size' => $rightSize,
            ]];
        }

        $diffPct = $this->sampleDiff($leftImg, $rightImg);

        return [[
            'type' => 'image',
            'status' => $diffPct === 0.0 ? 'identical' : 'different',
            'left_size' => $leftSize,
            'right_size' => $rightSize,
            'diff_pct' => $diffPct,
        ]];
    }

    public function supports(?string $contentType): bool
    {
        return $contentType !== null
            && (str_starts_with($contentType, 'image/png')
                || str_starts_with($contentType, 'image/jpeg')
                || str_starts_with($contentType, 'image/jpg')
                || str_starts_with($contentType, 'image/webp'));
    }

    public function name(): string
    {
        return 'image';
    }

    /**
     * Coarse 8x8 sample grid Euclidean colour distance. Returns 0.0 (identical)
     * to 1.0 (maximally different). Fast approximation suitable for MVP.
     */
    private function sampleDiff(ImageInterface $left, ImageInterface $right): float
    {
        $samples = 8;
        $width = $left->width();
        $height = $left->height();

        if ($width === 0 || $height === 0) {
            return 0.0;
        }

        $totalDistance = 0.0;
        $samplesTaken = 0;
        $maxDistance = sqrt(3 * (255 ** 2));

        for ($x = 0; $x < $samples; $x++) {
            for ($y = 0; $y < $samples; $y++) {
                $px = (int) (($x + 0.5) * $width / $samples);
                $py = (int) (($y + 0.5) * $height / $samples);

                $lc = $left->colorAt($px, $py);
                $rc = $right->colorAt($px, $py);

                $dr = $lc->red()->value() - $rc->red()->value();
                $dg = $lc->green()->value() - $rc->green()->value();
                $db = $lc->blue()->value() - $rc->blue()->value();

                $totalDistance += sqrt($dr * $dr + $dg * $dg + $db * $db);
                $samplesTaken++;
            }
        }

        if ($samplesTaken === 0) {
            return 0.0;
        }

        return min(1.0, ($totalDistance / $samplesTaken) / $maxDistance);
    }
}
