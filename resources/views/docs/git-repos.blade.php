<x-layouts.docs
    title="Git Repositories"
    description="Connect Git repositories to FleetQ and let agents read code, create branches, commit changes, and push to remote — all through MCP tools."
    page="git-repos"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Git Repositories</h1>
    <p class="mt-4 text-gray-600">
        FleetQ can connect to Git repositories and expose them to agents via MCP tools. Agents can inspect working
        trees, read commit history, make changes, create branches, and push — enabling coding workflows entirely
        within the agent pipeline.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A "Dependency Updater" agent clones your repo, runs
        <span class="font-mono text-xs">npm outdated</span>, creates a branch, bumps package versions, commits the
        changes, and opens a pull request — no human involved until review.</em>
    </p>

    {{-- Connecting a repository --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Connecting a repository</h2>
    <p class="mt-2 text-sm text-gray-600">
        Repositories are registered as <strong>Git Repository</strong> records scoped to your team. You can connect
        them via the UI or the API. Supported providers: GitHub, GitLab, Bitbucket, and any server accessible over
        SSH or HTTPS.
    </p>

    <div class="mt-4 space-y-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Via the UI</p>
            <ol class="mt-2 list-decimal pl-5 text-sm text-gray-600 space-y-1">
                <li>Navigate to <strong>Git Repositories → Connect Repository</strong>.</li>
                <li>Enter the remote URL (HTTPS or SSH).</li>
                <li>Select or create a <strong>Credential</strong> for authentication (personal access token, SSH key, or OAuth2 token).</li>
                <li>Save. FleetQ clones the repo and verifies connectivity.</li>
            </ol>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Via the API</p>
            <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/git-repositories') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "my-service",
    "remote_url": "https://github.com/acme/my-service.git",
    "credential_id": "cred_uuid",
    "default_branch": "main"
  }'</x-docs.code>
        </div>
    </div>

    <x-docs.callout type="tip">
        Store your personal access token or deploy key as a <strong>Credential</strong> first (type
        <span class="font-mono text-xs">api_key</span> or <span class="font-mono text-xs">bearer_token</span>),
        then reference its ID when connecting the repository. This keeps secrets out of repository records.
    </x-docs.callout>

    {{-- Git operations --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Git operations</h2>
    <p class="mt-2 text-sm text-gray-600">
        Once a repository is connected, agents can perform git operations through the MCP tools listed below.
        All operations are scoped to the repository's working directory on the FleetQ host.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">MCP tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_status</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Show the working tree status — staged, unstaged, and untracked files.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_log</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve commit history with optional branch, author, or path filters.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_diff</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">View changes between the working tree, index, or any two refs.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_branches</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List local and remote branches, create a new branch, or switch the current branch.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_commit</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Stage files and create a commit with a message. Supports <span class="font-mono">--all</span> and path-specific staging.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_push</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Push the current branch (or a named branch) to the configured remote.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_pull</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Pull the latest commits from the remote into the current branch.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_stash</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Stash uncommitted changes or pop the latest stash entry.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_blame</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Show per-line commit and author information for a file.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_checkout</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Restore files from a ref, or check out a specific commit or branch.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_merge</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Merge a branch into the current branch. Returns conflict information if the merge cannot complete cleanly.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_tag</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List, create, or delete lightweight and annotated tags.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">git_remote</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List configured remotes or add/remove a remote URL.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Use cases --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Use cases</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Code review automation</p>
            <p class="mt-1 text-sm text-gray-600">
                An agent fetches a pull request diff via <span class="font-mono text-xs">git_diff</span>, reviews it
                against your style guide, and posts inline comments or summaries as experiment artifacts.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Automated refactoring</p>
            <p class="mt-1 text-sm text-gray-600">
                An agent creates a branch, modifies files using the Filesystem tool, commits with
                <span class="font-mono text-xs">git_commit</span>, and pushes — ready for human review.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Documentation generation</p>
            <p class="mt-1 text-sm text-gray-600">
                An agent reads source files, generates documentation, writes it back to the repo, and opens a
                PR — keeping docs in sync automatically on every release.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Dependency updates</p>
            <p class="mt-1 text-sm text-gray-600">
                Combine the Bash tool with git tools: run <span class="font-mono text-xs">npm outdated</span>,
                apply safe updates, run tests, then commit and push only if tests pass.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Test generation</p>
            <p class="mt-1 text-sm text-gray-600">
                An agent reads changed files via <span class="font-mono text-xs">git_diff</span>, generates
                missing test coverage with an LLM skill, and commits the new test files to a dedicated branch.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Release tagging</p>
            <p class="mt-1 text-sm text-gray-600">
                A project run triggers an agent that bumps the version, commits the changelog, and creates an
                annotated tag via <span class="font-mono text-xs">git_tag</span> on every successful build.
            </p>
        </div>
    </div>

    {{-- Security --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Security</h2>
    <p class="mt-2 text-sm text-gray-600">
        Repository access is team-scoped — agents can only operate on repositories belonging to your team.
        Credentials are encrypted at rest using per-team envelope encryption and are never exposed in plaintext
        through the API or MCP tools.
    </p>

    <ul class="mt-3 list-disc pl-5 text-sm text-gray-600 space-y-1">
        <li>
            <strong>Explicit grant:</strong> agents only access repositories they're explicitly assigned to.
            No agent can read or write repositories from other teams or unassigned repos within your team.
        </li>
        <li>
            <strong>Credential isolation:</strong> push/pull credentials are resolved at runtime from the
            encrypted <span class="font-mono text-xs">Credential</span> record — never stored in the repo config.
        </li>
        <li>
            <strong>Audit trail:</strong> all git operations are recorded in the audit log with the agent,
            experiment, and timestamp, giving full traceability for compliance workflows.
        </li>
    </ul>

    <x-docs.callout type="warning">
        Give agents write access only when required. For read-only workflows (code review, documentation),
        use a deploy key or token with <strong>read-only</strong> scope on the provider side.
    </x-docs.callout>

    {{-- API endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/git-repositories</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all connected repositories.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/git-repositories</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Connect a new repository.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/git-repositories/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a repository by ID.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">PUT</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/git-repositories/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update repository settings (URL, credential, default branch).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">DELETE</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/git-repositories/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Disconnect a repository. Local clone is removed.</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-layouts.docs>
