<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conversation Simulation
    |--------------------------------------------------------------------------
    |
    | Persona-driven, multi-turn pre-deploy testing of agents. A user-simulator
    | LLM role-plays generated personas against a target agent; transcripts are
    | scored via the Evaluation LlmJudge. OFF by default — dark-shipped.
    |
    */

    'enabled' => env('SIMULATION_ENABLED', false),

    /*
    | Default provider/model for the user-simulator and persona generator,
    | "provider/model". The gateway still resolves team BYOK credentials; this
    | is only the default selection (mirrors evaluation.default_judge_model).
    */
    'default_model' => env('SIMULATION_DEFAULT_MODEL', 'anthropic/claude-sonnet-4-5'),

    'defaults' => [
        'persona_count' => 8,
        'max_turns' => 6,
        'pass_threshold' => 6.0,      // judge scores are 0–10
        'criteria' => ['relevance', 'correctness'],
    ],

    /*
    | Hard ceilings — multiplicative cost guard (personas × turns × 2 calls).
    | per_call_credit_ceiling becomes AiRequestDTO::maxCostCredits on every
    | simulator/judge call; the gateway BudgetEnforcement middleware enforces it.
    */
    'caps' => [
        'personas' => 25,
        'turns' => 8,
        'per_call_credit_ceiling' => env('SIMULATION_PER_CALL_CREDIT_CEILING', 2000),
    ],
];
