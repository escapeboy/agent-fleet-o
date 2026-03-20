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

];
