<?php

return [
    /*
     * Optional global GitHub API token used for fetching PR file lists and
     * file contents during the reverse Workflow YAML sync flow. Without a
     * token, the webhook handler falls back to anonymous (rate-limited 60/h).
     *
     * If your repo is private, this token MUST be set and have `repo` scope.
     */
    'api_token' => env('GITHUB_API_TOKEN'),

    /*
     * Fallback HMAC secret for the workflow-yaml webhook. Used only when
     * the team has no `git_webhook_secret` set. Configure a per-team secret
     * via the GitRepository UI in production; this fallback is for self-hosted
     * single-team deployments.
     */
    'workflow_webhook_secret' => env('GITHUB_WORKFLOW_WEBHOOK_SECRET'),
];
