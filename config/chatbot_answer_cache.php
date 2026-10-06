<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chatbot semantic answer cache
    |--------------------------------------------------------------------------
    |
    | Global kill switch. A chatbot is cached only when this is true AND its own
    | config.answer_cache.enabled is true (both default off).
    | Design: docs/design/design-chatbot-answer-cache.md
    |
    */

    'enabled' => (bool) env('CHATBOT_ANSWER_CACHE_ENABLED', false),

    // Candidates handed to the judge, ordered by cosine distance.
    'candidates' => 5,

    // Prefilter only: with nothing this close there is nothing to judge, so the
    // judge call is skipped. It never decides a hit on its own. Real paraphrases
    // sit around 0.25; 0.5 sent nearly every same-domain question to the judge.
    // Tune from the precision/recall evaluation.
    'candidate_max_distance' => 0.35,

    // Per-chatbot defaults (overridable in chatbots.config.answer_cache).
    'ttl_hours' => 168,
    'max_entries' => 500,
];
