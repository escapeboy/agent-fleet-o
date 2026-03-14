<?php

namespace App\Infrastructure\Git\DTOs;

readonly class GitPullRequestDTO
{
    public function __construct(
        public string $prNumber,
        public string $prUrl,
        public string $title,
        public string $status,
        public ?string $author = null,
        public ?string $createdAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'pr_number' => $this->prNumber,
            'pr_url' => $this->prUrl,
            'title' => $this->title,
            'status' => $this->status,
            'author' => $this->author,
            'created_at' => $this->createdAt,
        ];
    }
}
