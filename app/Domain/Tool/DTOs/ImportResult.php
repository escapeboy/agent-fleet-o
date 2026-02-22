<?php

namespace App\Domain\Tool\DTOs;

readonly class ImportResult
{
    public function __construct(
        public int $imported,
        public int $skipped,
        public int $failed,
        /** @var array<int, array{name: string, status: string, reason: string|null}> */
        public array $details,
    ) {}

    public function total(): int
    {
        return $this->imported + $this->skipped + $this->failed;
    }

    public function hasCredentialPlaceholders(): bool
    {
        foreach ($this->details as $detail) {
            if ($detail['status'] === 'imported' && ($detail['has_credentials'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function credentialCount(): int
    {
        $count = 0;
        foreach ($this->details as $detail) {
            if ($detail['status'] === 'imported' && ($detail['has_credentials'] ?? false)) {
                $count++;
            }
        }

        return $count;
    }
}
