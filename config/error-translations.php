<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Error Translations Dictionary
|--------------------------------------------------------------------------
|
| Maps technical exception strings to customer-readable translations
| with recommended recovery actions. Used by App\Domain\Shared\Services\
| ErrorTranslator to surface friendly diagnostics on failed experiments,
| paused projects, and unhealthy agents.
|
| Each entry:
|   - patterns: PCRE regexes; first match wins (top-to-bottom in this file)
|   - code:     internal stable code (use snake_case)
|   - mcp_code: maps to App\Mcp\ErrorCode for retryable hint
|   - retryable: bool — whether retry without changes is meaningful
|   - message: ['en' => ..., 'bg' => ...]
|   - actions: list of recommended actions; see ErrorTranslator::ACTION_KINDS
|
| Action kinds:
|   - 'route':     navigate to a Laravel route. target=route name, params=route params
|   - 'tool':      invoke an MCP tool. target=tool name, params=tool args (with placeholders)
|   - 'assistant': open assistant with prepopulated prompt. target=prompt text
|
| Action tiers (gates which roles can invoke):
|   - 'safe':        any member can run (retry, reload, navigate)
|   - 'config':      member+ — config change required (settings, billing)
|   - 'destructive': admin/owner only (delete, kill, hard-reset)
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | When ErrorTranslator falls back to the 'unknown' bucket, it logs and
    | (if enabled) increments a per-team Redis hash counter keyed by a hash
    | of the technical message. Lets future sprints harvest the most common
    | unmatched patterns from prod and expand the dictionary.
    |
    */
    'telemetry' => [
        'enabled' => env('ERROR_TRANSLATOR_TELEMETRY', true),
        'redis_connection' => env('ERROR_TRANSLATOR_REDIS_CONNECTION', 'cache'),
    ],

    // ── Rate limit / resource exhaustion ───────────────────────────────
    'rate_limit' => [
        'patterns' => [
            '/HTTP\s*429/i',
            '/RateLimit(Exceeded)?Exception/',
            '/rate.{0,3}limit.{0,10}exceeded/i',
            '/too\s+many\s+requests/i',
        ],
        'mcp_code' => 'RESOURCE_EXHAUSTED',
        'retryable' => true,
        'message' => [
            'en' => 'The AI provider rate-limited your account. Wait a minute and retry, or add a fallback provider key.',
            'bg' => 'AI доставчикът временно те ограничи. Изчакай малко и опитай пак, или добави резервен провайдер ключ.',
        ],
        'actions' => [
            [
                'kind' => 'tool',
                'label' => ['en' => 'Retry in 60s', 'bg' => 'Опитай пак след 60с'],
                'target' => 'experiment_retry',
                'params' => ['experiment_id' => '{experiment_id}'],
                'tier' => 'safe',
                'icon' => 'fa-rotate-right',
            ],
            [
                'kind' => 'route',
                'label' => ['en' => 'Add fallback provider key', 'bg' => 'Добави резервен ключ'],
                'target' => 'team.settings',
                'tier' => 'config',
                'icon' => 'fa-key',
            ],
        ],
    ],

    // ── Insufficient budget ────────────────────────────────────────────
    'budget_exceeded' => [
        'patterns' => [
            '/InsufficientBudgetException/',
            '/budget.{0,3}exceeded/i',
            '/insufficient\s+(credits?|budget)/i',
            '/no\s+credits\s+available/i',
        ],
        'mcp_code' => 'FAILED_PRECONDITION',
        'retryable' => false,
        'message' => [
            'en' => 'Your team is out of credits. Top up to resume.',
            'bg' => 'Кредитите на отбора са изчерпани. Зареди още, за да продължиш.',
        ],
        'actions' => [
            [
                'kind' => 'route',
                'label' => ['en' => 'Top up credits', 'bg' => 'Зареди кредити'],
                'target' => 'billing',
                'tier' => 'config',
                'icon' => 'fa-credit-card',
            ],
            [
                'kind' => 'route',
                'label' => ['en' => 'Adjust budget cap', 'bg' => 'Промени лимит'],
                'target' => 'projects.index',
                'tier' => 'config',
                'icon' => 'fa-sliders',
            ],
        ],
    ],

    // ── Invalid / missing API key ──────────────────────────────────────
    'invalid_api_key' => [
        'patterns' => [
            '/HTTP\s*401/i',
            '/(invalid|missing|incorrect|malformed)\s+(api[\s_-]?key|token|bearer)/i',
            '/Unauthorized.*provider/i',
            '/authentication[\s_-]?(error|failed)/i',
        ],
        'mcp_code' => 'PERMISSION_DENIED',
        'retryable' => false,
        'message' => [
            'en' => 'The AI provider rejected your API key. It may be missing, expired, or invalid.',
            'bg' => 'AI доставчикът отказа ключа ти. Може да е празен, изтекъл или невалиден.',
        ],
        'actions' => [
            [
                'kind' => 'route',
                'label' => ['en' => 'Update provider keys', 'bg' => 'Обнови провайдер ключовете'],
                'target' => 'team.settings',
                'tier' => 'config',
                'icon' => 'fa-key',
            ],
        ],
    ],

    // ── Provider unavailable / 5xx ─────────────────────────────────────
    'provider_unavailable' => [
        'patterns' => [
            '/HTTP\s*5\d\d/i',
            '/Service\s+Unavailable/i',
            '/upstream.{0,5}(error|failure|unavailable)/i',
            '/circuit.{0,3}breaker.{0,5}open/i',
        ],
        'mcp_code' => 'UNAVAILABLE',
        'retryable' => true,
        'message' => [
            'en' => 'The AI provider is having an outage or is temporarily unreachable. Retry, or switch to a fallback provider.',
            'bg' => 'AI доставчикът е недостъпен в момента. Опитай пак или превключи на резервен провайдер.',
        ],
        'actions' => [
            [
                'kind' => 'tool',
                'label' => ['en' => 'Retry now', 'bg' => 'Опитай пак'],
                'target' => 'experiment_retry',
                'params' => ['experiment_id' => '{experiment_id}'],
                'tier' => 'safe',
                'icon' => 'fa-rotate-right',
            ],
            [
                'kind' => 'route',
                'label' => ['en' => 'Configure fallback provider', 'bg' => 'Настрой резервен провайдер'],
                'target' => 'team.settings',
                'tier' => 'config',
                'icon' => 'fa-shuffle',
            ],
        ],
    ],

    // ── Blacklist (outbound rejected) ──────────────────────────────────
    'blacklisted' => [
        'patterns' => [
            '/BlacklistedException/',
            '/(recipient|target|address)\s+is\s+blacklisted/i',
        ],
        'mcp_code' => 'FAILED_PRECONDITION',
        'retryable' => false,
        'message' => [
            'en' => 'The recipient is on the team\'s blacklist and was not contacted. Remove them from the blacklist or change the target.',
            'bg' => 'Получателят е в blacklist-а на отбора и не беше потърсен. Премахни го или смени целта.',
        ],
        'actions' => [
            [
                'kind' => 'route',
                'label' => ['en' => 'Manage blacklist', 'bg' => 'Управлявай blacklist'],
                'target' => 'contacts.index',
                'tier' => 'config',
                'icon' => 'fa-ban',
            ],
        ],
    ],

    // ── Plan limit hit ─────────────────────────────────────────────────
    'plan_limit_exceeded' => [
        'patterns' => [
            '/PlanLimitExceededException/',
            '/plan.{0,3}limit.{0,10}(exceeded|reached)/i',
            '/upgrade\s+your\s+plan/i',
        ],
        'mcp_code' => 'FAILED_PRECONDITION',
        'retryable' => false,
        'message' => [
            'en' => 'You\'ve hit your current plan\'s limit. Upgrade to continue.',
            'bg' => 'Достигна лимита на текущия план. Премини на по-висок план, за да продължиш.',
        ],
        'actions' => [
            [
                'kind' => 'route',
                'label' => ['en' => 'View plans & upgrade', 'bg' => 'Виж плановете'],
                'target' => 'billing',
                'tier' => 'config',
                'icon' => 'fa-arrow-up-right-dots',
            ],
        ],
    ],

    // ── Postgres errors ────────────────────────────────────────────────
    'invalid_uuid' => [
        'patterns' => [
            '/SQLSTATE\[22P02\].*uuid/i',
            '/invalid\s+input\s+syntax\s+for\s+type\s+uuid/i',
        ],
        'mcp_code' => 'INVALID_ARGUMENT',
        'retryable' => false,
        'message' => [
            'en' => 'A reference ID in your data is malformed. The agent may have generated a placeholder instead of a real UUID.',
            'bg' => 'ID референция в данните е невалидна. Агентът може да е върнал placeholder вместо реален UUID.',
        ],
        'actions' => [
            [
                'kind' => 'assistant',
                'label' => ['en' => 'Ask assistant to fix', 'bg' => 'Питай асистента'],
                'target' => 'My experiment failed with an invalid UUID. Help me find which step generated a placeholder ID and recover from it.',
                'tier' => 'safe',
                'icon' => 'fa-wand-magic-sparkles',
            ],
        ],
    ],

    'db_connection_error' => [
        'patterns' => [
            '/SQLSTATE\[08\d{3}\]/i',
            '/connection\s+(refused|reset|closed)/i',
            '/server\s+(closed|terminated)\s+the\s+connection/i',
        ],
        'mcp_code' => 'UNAVAILABLE',
        'retryable' => true,
        'message' => [
            'en' => 'A temporary database hiccup. Retry usually works.',
            'bg' => 'Временен проблем с базата данни. Повторението обикновено помага.',
        ],
        'actions' => [
            [
                'kind' => 'tool',
                'label' => ['en' => 'Retry now', 'bg' => 'Опитай пак'],
                'target' => 'experiment_retry',
                'params' => ['experiment_id' => '{experiment_id}'],
                'tier' => 'safe',
                'icon' => 'fa-rotate-right',
            ],
        ],
    ],

    // ── Schema validation ──────────────────────────────────────────────
    'schema_validation_failed' => [
        'patterns' => [
            '/SchemaValidationException/',
            '/output\s+schema.{0,15}(invalid|failed|did not match)/i',
            '/JsonSchema.*(failed|violation)/i',
        ],
        'mcp_code' => 'FAILED_PRECONDITION',
        'retryable' => true,
        'message' => [
            'en' => 'The agent\'s output didn\'t match the required schema. Loosening the schema or switching to a more capable model usually fixes it.',
            'bg' => 'Изходът на агента не отговаря на схемата. Разхлабване на схемата или по-силен модел обикновено помагат.',
        ],
        'actions' => [
            [
                'kind' => 'tool',
                'label' => ['en' => 'Retry from this step', 'bg' => 'Повтори тази стъпка'],
                'target' => 'experiment_retry',
                'params' => ['experiment_id' => '{experiment_id}'],
                'tier' => 'safe',
                'icon' => 'fa-rotate-right',
            ],
            [
                'kind' => 'route',
                'label' => ['en' => 'Edit agent schema', 'bg' => 'Редактирай схемата'],
                'target' => 'agents.index',
                'tier' => 'config',
                'icon' => 'fa-pen',
            ],
        ],
    ],

    // ── Timeouts ───────────────────────────────────────────────────────
    'timeout' => [
        'patterns' => [
            '/DeadlineExceeded/i',
            '/(operation|request|stage)\s+timed?\s*out/i',
            '/HTTP\s*504/i',
        ],
        'mcp_code' => 'DEADLINE_EXCEEDED',
        'retryable' => true,
        'message' => [
            'en' => 'The operation took too long and timed out. The provider or a downstream tool is slow.',
            'bg' => 'Операцията продължи твърде дълго. Провайдерът или инструментът са бавни.',
        ],
        'actions' => [
            [
                'kind' => 'tool',
                'label' => ['en' => 'Retry now', 'bg' => 'Опитай пак'],
                'target' => 'experiment_retry',
                'params' => ['experiment_id' => '{experiment_id}'],
                'tier' => 'safe',
                'icon' => 'fa-rotate-right',
            ],
        ],
    ],

    // ── Tool / MCP errors ──────────────────────────────────────────────
    'tool_not_found' => [
        'patterns' => [
            '/Tool\s+(.+?)\s+not\s+found/i',
            '/Unknown\s+tool/i',
            '/MCP\s+tool.{0,15}(missing|not\s+registered)/i',
        ],
        'mcp_code' => 'NOT_FOUND',
        'retryable' => false,
        'message' => [
            'en' => 'The agent tried to call a tool that doesn\'t exist. Check the agent\'s tool bindings.',
            'bg' => 'Агентът извика инструмент, който не съществува. Провери тулсетовете на агента.',
        ],
        'actions' => [
            [
                'kind' => 'route',
                'label' => ['en' => 'Open agent tools', 'bg' => 'Отвори агент инструментите'],
                'target' => 'tools.index',
                'tier' => 'config',
                'icon' => 'fa-toolbox',
            ],
        ],
    ],

    // ── Integration auth needs refresh ─────────────────────────────────
    'integration_reauth_needed' => [
        'patterns' => [
            '/IntegrationRequiresReauth/',
            '/(integration|oauth).{0,10}(expired|requires.{0,5}re.?auth)/i',
            '/refresh.token.{0,10}(invalid|expired)/i',
        ],
        'mcp_code' => 'PERMISSION_DENIED',
        'retryable' => false,
        'message' => [
            'en' => 'A connected integration needs to be re-authorized. Open it and reconnect.',
            'bg' => 'Една от интеграциите ти изисква нов login. Отвори я и направи reconnect.',
        ],
        'actions' => [
            [
                'kind' => 'route',
                'label' => ['en' => 'Open integrations', 'bg' => 'Отвори интеграциите'],
                'target' => 'integrations.index',
                'tier' => 'config',
                'icon' => 'fa-link-slash',
            ],
        ],
    ],

    // ── Idempotency / duplicate ────────────────────────────────────────
    'duplicate_request' => [
        'patterns' => [
            '/IdempotencyKeyConflict/',
            '/duplicate\s+(request|key|entry)/i',
            '/SQLSTATE\[23505\]/',
        ],
        'mcp_code' => 'FAILED_PRECONDITION',
        'retryable' => false,
        'message' => [
            'en' => 'This action was already performed. The duplicate was rejected to keep your data consistent.',
            'bg' => 'Действието вече е изпълнено. Дубликатът беше отхвърлен, за да остане data-та чиста.',
        ],
        'actions' => [],
    ],

    // ── Unknown / fallback ─────────────────────────────────────────────
    // No pattern key — handled by ErrorTranslator::FALLBACK_CODE constant.
    'unknown' => [
        'patterns' => [],
        'mcp_code' => 'INTERNAL',
        'retryable' => true,
        'message' => [
            'en' => 'Something went wrong on our side. The platform team has the technical details. Try retrying — most issues are transient.',
            'bg' => 'Нещо се обърка от наша страна. Тех. детайлите са записани. Опитай пак — повечето проблеми са временни.',
        ],
        'actions' => [
            [
                'kind' => 'tool',
                'label' => ['en' => 'Retry now', 'bg' => 'Опитай пак'],
                'target' => 'experiment_retry',
                'params' => ['experiment_id' => '{experiment_id}'],
                'tier' => 'safe',
                'icon' => 'fa-rotate-right',
            ],
            [
                'kind' => 'assistant',
                'label' => ['en' => 'Ask assistant to investigate', 'bg' => 'Питай асистента'],
                'target' => 'My experiment {experiment_id} failed. Investigate the cause and recommend the next step.',
                'tier' => 'safe',
                'icon' => 'fa-magnifying-glass',
            ],
        ],
    ],
];
