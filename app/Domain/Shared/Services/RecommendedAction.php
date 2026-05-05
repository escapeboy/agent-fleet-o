<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

/**
 * Immutable recommended-action returned alongside a translated error.
 *
 * Surfaced to the customer as a clickable button. The UI layer is
 * responsible for routing each kind:
 *   - 'route'     → render <a href="route(target, params)">
 *   - 'tool'      → render wire:click that calls the matching MCP tool
 *   - 'assistant' → render wire:click that opens the assistant panel
 *                   pre-populated with `target` (the prompt text)
 *
 * Tier gates which roles can see/invoke the action:
 *   - 'safe'        → all members
 *   - 'config'      → member+ (anyone with edit-content)
 *   - 'destructive' → admin/owner only
 */
final readonly class RecommendedAction
{
    /**
     * @param  array<string, string>  $params  e.g. ['experiment_id' => '<uuid>']
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $target,
        public string $tier,
        public ?string $icon = null,
        public array $params = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'target' => $this->target,
            'tier' => $this->tier,
            'icon' => $this->icon,
            'params' => $this->params,
        ];
    }
}
