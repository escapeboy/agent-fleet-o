<?php

return [

    'default_judge_model' => env('EVALUATION_JUDGE_MODEL', 'anthropic/claude-sonnet-4-5'),
    'default_judge_provider' => env('EVALUATION_JUDGE_PROVIDER', 'anthropic'),

    'criteria' => [
        'faithfulness' => [
            'description' => 'Is the output grounded in the provided context?',
            'steps' => [
                'Break the output into individual factual claims',
                'Check each claim against the provided context',
                'Count supported vs unsupported claims',
            ],
            'rubric' => [
                [0, 2, 'Multiple unsupported or contradictory claims'],
                [3, 5, 'Some claims supported, some unsupported'],
                [6, 8, 'Most claims supported with minor gaps'],
                [9, 10, 'All claims fully supported by context'],
            ],
        ],
        'relevance' => [
            'description' => 'Does the output directly address the task?',
            'steps' => [
                'Identify the core question or task in the input',
                'Assess whether the output directly addresses it',
                'Check for unnecessary tangential information',
            ],
            'rubric' => [
                [0, 2, 'Response is off-topic or irrelevant'],
                [3, 5, 'Partially relevant but misses key aspects'],
                [6, 8, 'Relevant with minor tangential content'],
                [9, 10, 'Precisely addresses the question'],
            ],
        ],
        'correctness' => [
            'description' => 'Is the output factually correct?',
            'steps' => [
                'Identify factual statements in the output',
                'Verify against known facts and expected output',
                'Rate overall accuracy',
            ],
            'rubric' => [
                [0, 2, 'Contains significant factual errors'],
                [3, 5, 'Mix of correct and incorrect information'],
                [6, 8, 'Mostly correct with minor inaccuracies'],
                [9, 10, 'Fully correct and verified'],
            ],
        ],
        'completeness' => [
            'description' => 'Does the output cover all required aspects?',
            'steps' => [
                'List all aspects the task requires',
                'Check which aspects are covered in the output',
                'Assess depth of coverage',
            ],
            'rubric' => [
                [0, 2, 'Major aspects missing'],
                [3, 5, 'Some aspects covered, some missing'],
                [6, 8, 'Most aspects covered adequately'],
                [9, 10, 'All aspects thoroughly covered'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agentic AI Flywheel (all default-OFF — see docs/architecture/architecture-agentic-flywheel.md)
    |--------------------------------------------------------------------------
    */

    /** Aggregate scores below this are treated as a failing eval case. */
    'regression_threshold' => (float) env('EVALUATION_REGRESSION_THRESHOLD', 7.0),

    // #1 Auto-eval at triage: append a deferred regression case when a failure mode is named.
    'auto_eval' => [
        'enabled' => (bool) env('EVAL_AUTO_EVAL_AT_TRIAGE', false),
        'dataset_name' => env('EVAL_AUTO_EVAL_DATASET_NAME', 'Production Regressions'),
    ],

    // #3 Error-mode catalog: cluster named failures into a per-team taxonomy with lever assignment.
    'error_mode_catalog' => [
        'enabled' => (bool) env('EVAL_ERROR_MODE_CATALOG', false),
    ],

    // #5 Production eval monitor: run the eval set on sampled production traffic as a continuous monitor.
    'production_monitor' => [
        'enabled' => (bool) env('EVAL_PRODUCTION_MONITOR', false),
        'sample_size' => (int) env('EVAL_MONITOR_SAMPLE_SIZE', 20),
    ],

    // #4 Drift monitor: four signals (input shift, eval decay, thumbs-down, latency/cost).
    'drift_monitor' => [
        'enabled' => (bool) env('EVAL_DRIFT_MONITOR', false),
        'window_hours' => (int) env('EVAL_DRIFT_WINDOW_HOURS', 24),
        'baseline_hours' => (int) env('EVAL_DRIFT_BASELINE_HOURS', 168),
        'notify_on_breach' => (bool) env('EVAL_DRIFT_NOTIFY', false),
        'thresholds' => [
            'eval_score_decay' => (float) env('EVAL_DRIFT_SCORE_DECAY', 1.0),
            'thumbs_down_rate' => (float) env('EVAL_DRIFT_THUMBS_DOWN_RATE', 0.15),
            'latency_p95_mult' => (float) env('EVAL_DRIFT_LATENCY_MULT', 1.5),
            'cost_avg_mult' => (float) env('EVAL_DRIFT_COST_MULT', 1.5),
            'input_novelty_rate' => (float) env('EVAL_DRIFT_INPUT_NOVELTY', 0.4),
        ],
    ],

];
