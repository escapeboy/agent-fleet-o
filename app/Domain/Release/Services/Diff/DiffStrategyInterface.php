<?php

declare(strict_types=1);

namespace App\Domain\Release\Services\Diff;

/**
 * Strategy contract for content diffing. Each implementation handles a single
 * content-type family (text, JSON, image). The resolver picks one based on the
 * artifact's metadata + content sniffing.
 *
 * The output array shape is union'd across strategies — consumers must check
 * the `type` field on each segment to render correctly:
 *
 *   text:    {type: context|add|remove|unsupported, left: ?int, right: ?int, text: string}
 *   json:    {type: change|add|remove|unchanged|unsupported, path: string, left: mixed, right: mixed}
 *   image:   single segment with {type: image, left_size, right_size, diff_pct, overlay_url}
 */
interface DiffStrategyInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function diff(?string $left, ?string $right, array $context = []): array;

    public function supports(?string $contentType): bool;

    public function name(): string;
}
