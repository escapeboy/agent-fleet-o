<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\DTOs;

/**
 * Immutable DTO carrying the parsed attributes of a single code element
 * (file, class, function, or method) extracted from a PHP source file.
 */
readonly class CodeElementData
{
    public function __construct(
        /** 'file' | 'class' | 'function' | 'method' */
        public string $elementType,
        public string $name,
        /** Relative path from the repository root. */
        public string $filePath,
        public ?int $lineStart,
        public ?int $lineEnd,
        /** First meaningful source line — class/function/method signature. */
        public ?string $signature,
        /** Raw PHPDoc comment, if present. */
        public ?string $docstring,
        /** SHA-256 of (signature + docstring), used for change detection. */
        public string $contentHash,
    ) {}
}
