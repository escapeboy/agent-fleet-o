<?php

/**
 * Platform features a team may switch on or off for itself.
 *
 * A feature runs for a team only when BOTH hold:
 *   1. the platform switch at `platform` (a dotted config path) is on — a team
 *      can never turn on something the platform has off; `null` = no platform
 *      switch;
 *   2. the team's own choice, stored in teams.settings under `setting`
 *      (default `features.<key>`), is on — or, when the team never chose,
 *      `default`.
 *
 * Read through App\Domain\Shared\Services\TeamFeatures::enabled(). Adding an
 * entry here only lists it; the feature's read site must call enabled() for
 * the team's choice to take effect.
 */

return [

    'chatbot' => [
        'label' => 'Chatbots',
        'group' => 'Chatbot',
        'description' => 'Public chatbots backed by your agents and knowledge base (Chatbots menu).',
        'platform' => null,
        'setting' => 'chatbot_enabled',
        'default' => false,
    ],

    'semantic_cache' => [
        'label' => 'Semantic response cache',
        'group' => 'LLM',
        'description' => 'Reuse a stored answer when a near-identical prompt was already answered. Saves cost; turn off if every call must reach the model.',
        'platform' => 'semantic_cache.enabled',
        'default' => true,
    ],

    'loop_detection' => [
        'label' => 'Agent loop detection',
        'group' => 'Agents',
        'description' => 'Detect an agent repeating the same prompt/answer cycle and alert (or pause it).',
        'platform' => 'loop_detection.enabled',
        'default' => true,
    ],

    'agent_eval_gate' => [
        'label' => 'Evaluation gate on agent changes',
        'group' => 'Agents',
        'description' => 'Score a changed agent configuration against its evaluation dataset before it is applied.',
        'platform' => 'agent.eval_gate.enabled',
        'default' => true,
    ],

    'agent_planning_tool' => [
        'label' => 'Agent planning tool',
        'group' => 'Agents',
        'description' => 'Give agents an update_plan tool to keep a live to-do list while they work.',
        'platform' => 'agent.planning_tool.enabled',
        'default' => true,
    ],

    'programmatic_tool_calling' => [
        'label' => 'Programmatic tool calling',
        'group' => 'Agents',
        'description' => 'Let agents run a short program that calls several tools at once (run_tool_program), keeping large tool output out of the context.',
        'platform' => 'agent.programmatic_tool_calling.enabled',
        'default' => true,
    ],

    'agent_prompt_optimizer' => [
        'label' => 'Agent prompt optimizer',
        'group' => 'Agents',
        'description' => 'Propose improved agent prompts scored against the evaluation dataset (proposals still need approval).',
        'platform' => 'agent.prompt_optimizer.enabled',
        'default' => true,
    ],

    'memory_contextual_rag' => [
        'label' => 'Contextual memory indexing',
        'group' => 'Memory',
        'description' => 'Add a short LLM-written context to each memory chunk before embedding it. Better recall, one small LLM call per chunk.',
        'platform' => 'memory.contextual_rag.enabled',
        'default' => true,
    ],

    'memory_deep_judgment' => [
        'label' => 'Memory relevance judge',
        'group' => 'Memory',
        'description' => 'Let a model re-check which retrieved memories are actually relevant before they are injected.',
        'platform' => 'memory.deep_judgment.enabled',
        'default' => true,
    ],

    'auto_eval' => [
        'label' => 'Regression cases from failures',
        'group' => 'Evaluation',
        'description' => 'When an experiment fails, add its input as a regression case to the evaluation dataset.',
        'platform' => 'evaluation.auto_eval.enabled',
        'default' => true,
    ],

    'bug_report_triage' => [
        'label' => 'Bug report auto-triage',
        'group' => 'Signals',
        'description' => 'Classify automatically captured bug reports with a model.',
        'platform' => 'signals.bug_report.triage_classifier_enabled',
        'default' => true,
    ],

    'product_graph' => [
        'label' => 'ProductGraph',
        'group' => 'Product',
        'description' => 'Product graph browser, change log and impact analysis pages.',
        'platform' => 'productgraph.enabled',
        'default' => true,
    ],

];
