<?php

return [
    /*
     | Master switch for the ProductGraph capability (UI routes + MCP tools
     | still load, but pages short-circuit to a disabled notice and seeding
     | is gated). Flip via PRODUCT_GRAPH_ENABLED=true.
     */
    'enabled' => (bool) env('PRODUCT_GRAPH_ENABLED', false),

    /*
     | Maximum traversal depth for blast-radius / impact analysis.
     */
    'max_impact_depth' => (int) env('PRODUCT_GRAPH_MAX_IMPACT_DEPTH', 5),
];
