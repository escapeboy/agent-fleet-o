<?php

namespace App\Domain\Agent\Enums;

enum AgentEnvironment: string
{
    /** No tools — pure LLM reasoning */
    case Minimal = 'minimal';

    /** Bash + filesystem for code editing tasks */
    case Coding = 'coding';

    /** Browser + web search for research tasks */
    case Browsing = 'browsing';

    /** Read-only tools (list/search/get operations) for safe discovery */
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Minimal => 'Minimal (no tools)',
            self::Coding => 'Coding (bash + filesystem)',
            self::Browsing => 'Browsing (web + search)',
            self::Restricted => 'Restricted (read-only)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Minimal => 'No tools attached — pure LLM reasoning only.',
            self::Coding => 'Auto-attaches bash and filesystem tools for code editing tasks.',
            self::Browsing => 'Auto-attaches browser and web search tools for research.',
            self::Restricted => 'Read-only operations (list/search/get/read) — safe for discovery and audit agents.',
        };
    }

    /** Tool slugs automatically attached when this environment is selected. */
    public function toolSlugs(): array
    {
        return match ($this) {
            self::Minimal => [],
            self::Coding => ['bash', 'filesystem'],
            self::Browsing => ['browser', 'web_search'],
            self::Restricted => [],
        };
    }

    /** Tool tag prefixes considered "safe" for this environment (used as an additional filter). */
    public function safeTagPrefixes(): array
    {
        return match ($this) {
            self::Restricted => ['list_', 'search_', 'get_', 'read_'],
            default => [],
        };
    }
}
