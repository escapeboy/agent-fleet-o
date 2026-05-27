<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Polyglot code index (CodeGraph extractor)
    |--------------------------------------------------------------------------
    |
    | When enabled AND the `codegraph` binary is on PATH, IndexRepositoryAction
    | runs a second extraction pass over NON-PHP source files using CodeGraph's
    | tree-sitter extractor (batch `codegraph index`), mapping its SQLite output
    | into code_elements / code_edges. PHP files always stay on nikic/php-parser.
    |
    | Default off: merging is a no-op until the image ships the binary and this
    | flag is flipped — the 30-second rollback path is a flag flip, not a revert.
    |
    */
    'polyglot_index' => env('GIT_REPOSITORY_POLYGLOT_INDEX', false),

    // Binary name / path for the CodeGraph CLI (resolved via `which`).
    'codegraph_bin' => env('GIT_REPOSITORY_CODEGRAPH_BIN', 'codegraph'),

    // Seconds the `codegraph index` subprocess may run before being killed.
    'codegraph_timeout' => (int) env('GIT_REPOSITORY_CODEGRAPH_TIMEOUT', 180),

    // Safety caps for temp-dir materialization on large repos.
    'polyglot_max_files' => (int) env('GIT_REPOSITORY_POLYGLOT_MAX_FILES', 5000),
    'polyglot_max_file_bytes' => (int) env('GIT_REPOSITORY_POLYGLOT_MAX_FILE_BYTES', 1048576),

    /*
    | Non-PHP source extensions the polyglot pass materializes and indexes.
    | PHP is deliberately absent — owned by PhpCodeParser.
    */
    'polyglot_extensions' => [
        'ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs',
        'py', 'go', 'rs', 'java', 'cs', 'rb',
        'c', 'h', 'cpp', 'hpp', 'cc', 'swift',
        'kt', 'kts', 'scala', 'dart', 'vue', 'svelte', 'lua',
    ],
];
