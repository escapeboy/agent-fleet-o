<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;

final class ProgressTrackerArtifact extends BaseArtifact
{
    public const TYPE = 'progress_tracker';

    private const MAX_LABEL_CHARS = 100;

    private const MAX_ETA_CHARS = 50;

    private const ALLOWED_STATES = ['pending', 'running', 'completed', 'failed', 'paused'];

    public function __construct(
        public readonly string $label,
        public readonly int $progress,
        public readonly string $state,
        public readonly ?string $eta,
        public readonly ?string $sourceTool,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function sourceTool(): ?string
    {
        return $this->sourceTool;
    }

    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static
    {
        $label = StringSanitizer::clean($raw['label'] ?? null, self::MAX_LABEL_CHARS);
        if ($label === null) {
            return null;
        }

        if (! is_numeric($raw['progress'] ?? null)) {
            return null;
        }
        $progress = (int) StringSanitizer::clampNumber($raw['progress'], 0, 100);

        $state = is_string($raw['state'] ?? null) ? strtolower($raw['state']) : 'running';
        if (! in_array($state, self::ALLOWED_STATES, true)) {
            $state = 'running';
        }

        $eta = StringSanitizer::clean($raw['eta'] ?? null, self::MAX_ETA_CHARS);

        $sourceTool = StringSanitizer::clean($raw['source_tool'] ?? null, 64);
        if ($sourceTool !== null && ! self::toolRanInTurn($sourceTool, $toolCallsInTurn)) {
            return null;
        }

        return new self(
            label: $label,
            progress: $progress,
            state: $state,
            eta: $eta,
            sourceTool: $sourceTool,
        );
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'label' => $this->label,
            'progress' => $this->progress,
            'state' => $this->state,
            'eta' => $this->eta,
            'source_tool' => $this->sourceTool,
        ];
    }
}
