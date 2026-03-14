<?php

namespace App\Infrastructure\Git\DTOs;

readonly class GitFileDTO
{
    public function __construct(
        public string $path,
        public string $name,
        public string $type, // 'file' | 'dir'
        public ?string $content = null,
        public ?string $sha = null,
        public ?int $size = null,
        public ?string $encoding = null,
    ) {}

    public function decodedContent(): string
    {
        if ($this->encoding === 'base64' && $this->content !== null) {
            return base64_decode($this->content);
        }

        return $this->content ?? '';
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'type' => $this->type,
            'size' => $this->size,
            'sha' => $this->sha,
        ];
    }
}
