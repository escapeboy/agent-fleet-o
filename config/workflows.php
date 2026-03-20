<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Workflow Recursion Depth
    |--------------------------------------------------------------------------
    |
    | When workflows are invoked as tools (workflow-as-tool), this limits
    | how deep the nesting can go. This applies to both sub-workflows
    | and workflow-as-tool invocations.
    |
    */
    'max_recursion_depth' => (int) env('WORKFLOW_MAX_RECURSION_DEPTH', 5),

    /*
    |--------------------------------------------------------------------------
    | Synchronous Execution Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum seconds a workflow-as-tool execution can run before timing out.
    |
    */
    'sync_execution_timeout' => (int) env('WORKFLOW_SYNC_TIMEOUT', 120),
];
