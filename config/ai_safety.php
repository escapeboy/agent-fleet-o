<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway-Level AI Safety Classifier
    |--------------------------------------------------------------------------
    |
    | Middleware that inspects LLM request input and response output for
    | unsafe patterns (jailbreak attempts, configured rule packs). Runs on
    | every gateway call regardless of attached guardrail Skills, providing
    | a defense-in-depth net.
    |
    | Disabled platform-wide by default. Teams individually opt-in via
    | team.settings['safety_classifier_enabled'] = true.
    |
    */

    'enabled' => env('AI_SAFETY_ENABLED', true),

    /*
    | Mode controls how violations are handled:
    |   - 'advisory': pass response through unchanged; log + emit event only
    |   - 'block':    rewrite response to refusal message; clear parsed output
    */
    'mode' => env('AI_SAFETY_MODE', 'advisory'),

    /*
    | Refusal message used when mode = 'block' and a violation is detected.
    */
    'refusal_message' => env(
        'AI_SAFETY_REFUSAL_MESSAGE',
        'This response was blocked by your team\'s AI safety policy.',
    ),

    /*
    | Strike counter window — number of violations per team within this many
    | seconds before the team is auto-flagged in the audit log. Set to 0 to
    | disable strike tracking entirely.
    */
    'strike_window_seconds' => env('AI_SAFETY_STRIKE_WINDOW', 3600),
    'strike_threshold' => env('AI_SAFETY_STRIKE_THRESHOLD', 5),

    /*
    | Built-in rule packs. Each rule has:
    |   - id:       unique slug, surfaces in audit + event payload
    |   - kind:     'regex' (PHP regex) | 'contains' (case-insensitive substring)
    |   - target:   'input' | 'output' | 'both'
    |   - pattern:  the regex or substring
    |   - severity: 'low' | 'medium' | 'high' — informational, not enforced here
    */
    'rules' => [
        // Common jailbreak prompts
        [
            'id' => 'jailbreak-dan',
            'kind' => 'contains',
            'target' => 'input',
            'pattern' => 'DAN mode',
            'severity' => 'medium',
        ],
        [
            'id' => 'jailbreak-ignore-previous',
            'kind' => 'regex',
            'target' => 'input',
            'pattern' => '/\bignore\s+(all\s+)?(previous|prior|above)\s+(instructions|prompts|rules)\b/i',
            'severity' => 'medium',
        ],
        [
            'id' => 'jailbreak-developer-mode',
            'kind' => 'regex',
            'target' => 'input',
            'pattern' => '/\b(developer|admin|root|sudo)\s+mode\s+(on|enabled|activated)\b/i',
            'severity' => 'medium',
        ],
        [
            'id' => 'prompt-injection-system',
            'kind' => 'regex',
            'target' => 'input',
            'pattern' => '/\b(you\s+are\s+now|act\s+as|pretend\s+(to\s+be|you\s+are))\s+(?!a\s+(helpful|professional|polite))/i',
            'severity' => 'low',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typed Guardrail Scanners
    |--------------------------------------------------------------------------
    |
    | Purpose-built scanners (App\Infrastructure\AI\Guardrails\Scanners\*) that
    | run inside SafetyClassifier alongside the regex/contains rule packs above.
    | They catch high-value cases generic regex handles poorly: invisible-char
    | / ASCII-smuggling, leaked secrets (reuses SecretPatternLibrary), and
    | structured PII. Gated independently of the rule packs and OFF by default.
    |
    | Each scanner entry: enabled (bool), target ('input'|'output'|'both'),
    | severity ('low'|'medium'|'high'|'critical'), plus scanner-specific options.
    | Block-vs-advisory remains governed by the global 'mode' above.
    |
    */
    'scanners_enabled' => env('AI_SAFETY_SCANNERS_ENABLED', false),

    'scanners' => [
        'invisible_chars' => ['enabled' => true, 'target' => 'both', 'severity' => 'high'],
        'secrets' => ['enabled' => true, 'target' => 'output', 'severity' => 'critical'],
        'pii' => ['enabled' => true, 'target' => 'output', 'severity' => 'high'],
        'prompt_injection' => ['enabled' => true, 'target' => 'input', 'severity' => 'high'],
        'jailbreak' => ['enabled' => true, 'target' => 'input', 'severity' => 'high'],
        'url' => ['enabled' => false, 'target' => 'output', 'severity' => 'low', 'allowlist' => []],
        'profanity' => ['enabled' => false, 'target' => 'output', 'severity' => 'low', 'words' => []],
        'code_exfil' => ['enabled' => false, 'target' => 'output', 'severity' => 'medium', 'min_bytes' => 512],
    ],

    /*
    | Optional callable resolver for an LLM-based safety classifier. When set,
    | the resolver returns an instance with a `classify(string $content,
    | string $direction): array` method that yields ['safe' => bool,
    | 'reason' => string, 'rule_id' => string]. Off by default — pattern
    | matching only this sprint.
    */
    'llm_classifier' => env('AI_SAFETY_LLM_CLASSIFIER'),

];
