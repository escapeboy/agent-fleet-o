<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Enums\ExecutionTier;
use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Opt-in middleware that detects ambiguous task inputs before the agent begins execution.
 * When ambiguity exceeds the configured threshold, sets requiresClarification = true
 * and short-circuits the pipeline (does NOT call $next).
 *
 * Enabled per-agent via: agent.config.clarification_detection_enabled = true
 * Threshold configurable via: agent.config.clarification_threshold (default 0.75)
 */
class DetectClarificationNeeded
{
    private const CONFIG_ENABLED_KEY = 'clarification_detection_enabled';

    private const CONFIG_THRESHOLD_KEY = 'clarification_threshold';

    private const DEFAULT_THRESHOLD = 0.75;

    private const DETECT_MODEL = 'claude-haiku-4-5';

    /**
     * Whitelist of form field types the renderer knows how to display.
     * Anything not in this set is silently dropped from LLM-generated schemas.
     * Keep in sync with HumanTaskForm::initFormData() / buildValidationRules()
     * and human-task-form.blade.php render branches.
     */
    private const ALLOWED_FIELD_TYPES = [
        'textarea', 'text', 'number', 'select', 'multi_select',
        'radio_cards', 'checkbox', 'boolean', 'date',
    ];

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        $config = $ctx->agent->config ?? [];

        // Skip if: nested agent-as-tool call, Flash tier, opt-out, or answer already provided
        if (! empty($ctx->input['_is_nested_call'])
            || ExecutionTier::fromConfig($config) === ExecutionTier::Flash
            || ! ($config[self::CONFIG_ENABLED_KEY] ?? false)
            || isset($ctx->input['clarification_answer'])
        ) {
            return $next($ctx);
        }

        try {
            $team = Team::find($ctx->teamId);
            $resolved = $this->providerResolver->resolve(agent: $ctx->agent, team: $team);

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: self::DETECT_MODEL,
                systemPrompt: <<<'PROMPT'
                Analyze the task input for ambiguity. Return ONLY valid JSON (no markdown, no prose).

                Shape:
                {
                  "ambiguity_score": 0.0-1.0,      // 0 = completely clear, 1 = completely ambiguous
                  "question": "<single most important clarifying question, or '' if score < 0.5>",
                  "ambiguities": ["<specific ambiguous aspect>", ...],
                  "form_schema": {                  // OPTIONAL — omit if plain freeform text is best
                    "fields": [
                      {
                        "name": "<snake_case identifier, max 40 chars>",
                        "label": "<human-readable prompt, max 200 chars>",
                        "type": "text|textarea|number|select|multi_select|radio_cards|checkbox|date",
                        "required": true|false,
                        // type-specific:
                        "options": [{"value": "...", "label": "..."}], // select, multi_select, radio_cards
                        "min": <number>, "max": <number>,              // number only
                        "placeholder": "<hint>",
                        "help": "<one-line help text>"
                      }
                    ]
                  }
                }

                Rules for form_schema:
                - Prefer structured fields (select, radio_cards, number with bounds) over freeform text when the
                  clarification has a small set of valid answers.
                - Use radio_cards for 2-4 mutually exclusive choices when visual prominence matters.
                - Use select for 5+ choices.
                - Use multi_select when the user can pick several.
                - Use number only when the expected answer is quantitative; always set min/max when sensible.
                - Use date for calendar-like answers.
                - Fall back to textarea ONLY when the question is truly open-ended.
                - Omit form_schema entirely if a plain textarea is best — the renderer will default to one.
                - Never include more than 6 fields. Keep it minimal.
                PROMPT,
                userPrompt: json_encode($ctx->input),
                maxTokens: 512,
                teamId: $ctx->teamId,
                agentId: $ctx->agent->id,
                experimentId: $ctx->experimentId,
                purpose: 'agent.clarification_detect',
                temperature: 0.0,
            ));

            $result = json_decode($response->content, true);

            if (! is_array($result)) {
                return $next($ctx);
            }

            $score = (float) ($result['ambiguity_score'] ?? 0.0);
            $threshold = (float) ($config[self::CONFIG_THRESHOLD_KEY] ?? self::DEFAULT_THRESHOLD);
            $question = trim($result['question'] ?? '');

            if ($score >= $threshold && $question !== '') {
                $ctx->requiresClarification = true;
                $ctx->clarificationQuestion = $question;
                $ctx->clarificationFormSchema = $this->sanitizeFormSchema($result['form_schema'] ?? null);

                Log::info('DetectClarificationNeeded: clarification required', [
                    'agent_id' => $ctx->agent->id,
                    'experiment_id' => $ctx->experimentId,
                    'score' => $score,
                    'threshold' => $threshold,
                    'question' => $question,
                    'form_field_count' => $ctx->clarificationFormSchema
                        ? count($ctx->clarificationFormSchema['fields'] ?? [])
                        : 0,
                ]);

                // Short-circuit: do not call $next
                return $ctx;
            }
        } catch (\Throwable $e) {
            // Detection failure is non-fatal — proceed with execution
            Log::warning('DetectClarificationNeeded: detection failed, proceeding with execution', [
                'agent_id' => $ctx->agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($ctx);
    }

    /**
     * Strictly validate and normalize an LLM-proposed form schema before it
     * ever reaches the database or the renderer. Everything LLM-generated is
     * untrusted input: we drop unknown keys, cap string lengths, whitelist
     * field types, and discard malformed options.
     *
     * Returns null when nothing useful survives — the renderer falls back to
     * a single textarea in that case.
     *
     * @param  mixed  $raw
     * @return array{fields: list<array<string, mixed>>}|null
     */
    private function sanitizeFormSchema($raw): ?array
    {
        if (! is_array($raw) || ! isset($raw['fields']) || ! is_array($raw['fields'])) {
            return null;
        }

        $fields = [];
        foreach (array_slice($raw['fields'], 0, 6) as $i => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = is_string($field['type'] ?? null) ? strtolower($field['type']) : 'textarea';
            if (! in_array($type, self::ALLOWED_FIELD_TYPES, true)) {
                continue;
            }

            $name = is_string($field['name'] ?? null) ? $field['name'] : "field_{$i}";
            $name = preg_replace('/[^a-z0-9_]/i', '_', $name) ?? "field_{$i}";
            $name = substr($name, 0, 40) ?: "field_{$i}";

            $label = is_string($field['label'] ?? null) ? strip_tags($field['label']) : '';
            $label = substr($label, 0, 200);

            $sanitized = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
            ];

            if (isset($field['help']) && is_string($field['help'])) {
                $sanitized['help'] = substr(strip_tags($field['help']), 0, 200);
            }

            if (isset($field['placeholder']) && is_string($field['placeholder'])) {
                $sanitized['placeholder'] = substr(strip_tags($field['placeholder']), 0, 100);
            }

            if (in_array($type, ['select', 'multi_select', 'radio_cards'], true)) {
                $options = [];
                foreach (array_slice($field['options'] ?? [], 0, 20) as $opt) {
                    if (! is_array($opt)) {
                        continue;
                    }
                    $value = is_scalar($opt['value'] ?? null) ? (string) $opt['value'] : null;
                    $optLabel = is_string($opt['label'] ?? null) ? strip_tags($opt['label']) : $value;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $options[] = [
                        'value' => substr($value, 0, 100),
                        'label' => substr($optLabel ?? $value, 0, 100),
                    ];
                }

                if ($options === []) {
                    // No valid options → degrade to textarea instead of breaking.
                    $sanitized['type'] = 'textarea';
                } else {
                    $sanitized['options'] = $options;
                }
            }

            if ($type === 'number') {
                if (isset($field['min']) && is_numeric($field['min'])) {
                    $sanitized['min'] = (float) $field['min'];
                }
                if (isset($field['max']) && is_numeric($field['max'])) {
                    $sanitized['max'] = (float) $field['max'];
                }
            }

            $fields[] = $sanitized;
        }

        if ($fields === []) {
            return null;
        }

        return ['fields' => $fields];
    }
}
