<?php

declare(strict_types=1);

namespace App\Domain\Assistant\DTOs;

/**
 * Resolved provider/model and capability flags for an assistant turn, after
 * applying team/global defaults, local-agent detection, and relay rewriting.
 * Bundles the values SendAssistantMessageAction::execute previously threaded as
 * loose locals so the capability-resolution logic lives in one place.
 */
final readonly class AssistantTarget
{
    public function __construct(
        public string $provider,
        public string $model,
        public bool $isLocal,
        public bool $supportsToolLoop,
        public bool $supportsMcpNatively,
        public bool $canExecuteTools,
    ) {}
}
