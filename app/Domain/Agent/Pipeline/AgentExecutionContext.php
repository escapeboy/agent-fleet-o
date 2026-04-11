<?php

namespace App\Domain\Agent\Pipeline;

use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Models\Project;

/**
 * Value object passed through the semantic middleware pipeline in ExecuteAgentAction.
 * Middleware can enrich the context (add system prompt parts, summarize input)
 * or gate execution (set requiresClarification to short-circuit the pipeline).
 */
final class AgentExecutionContext
{
    public function __construct(
        public readonly Agent $agent,
        public readonly string $teamId,
        public readonly string $userId,
        public readonly ?string $experimentId,
        public readonly ?Project $project,
        /** Mutable input array — middleware may summarize or augment it */
        public array $input,
        /** Accumulated system prompt sections added by middleware */
        public array $systemPromptParts = [],
        /** Set by DetectClarificationNeeded to interrupt execution */
        public bool $requiresClarification = false,
        /** The clarifying question to surface in the ApprovalInbox */
        public ?string $clarificationQuestion = null,
        /**
         * Optional JSON-Schema-ish form spec for the clarification UI.
         * When set, HumanTaskForm renders these fields instead of the
         * default single textarea. Shape is a flat fields array:
         *   [['name' => 'answer', 'type' => 'select', 'options' => [...], ...], ...]
         */
        public ?array $clarificationFormSchema = null,
        /** Set by SummarizeContext when input was compressed */
        public bool $contextSummarized = false,
        /** Set by PreExecutionScout — targeted knowledge queries to improve memory/KG retrieval */
        public array $scoutQueries = [],
    ) {}
}
