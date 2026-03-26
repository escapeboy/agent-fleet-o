<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool for generating changelogs from git commit history.
 *
 * Groups commits by type (feat, fix, chore, etc.) using Conventional Commits
 * format and generates a structured changelog entry for a given version.
 */
class GitChangelogTool extends Tool
{
    protected string $name = 'git_changelog_generate';

    protected string $description = 'Generate a changelog entry from git commit history between two refs. Groups commits by conventional commit type (feat, fix, chore, docs, etc.) and formats a Markdown changelog.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'version' => $schema->string()
                ->description('Version string for the changelog header (e.g. v1.5.0)')
                ->required(),
            'from_ref' => $schema->string()
                ->description('Git ref to start from (tag, branch, or SHA). Defaults to the latest tag.'),
            'to_ref' => $schema->string()
                ->description('Git ref to end at (tag, branch, or SHA). Defaults to HEAD.'),
            'include_authors' => $schema->boolean()
                ->description('Include commit authors in the changelog (default: false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = GitRepository::find($request->get('repository_id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        $version = $request->get('version');
        $fromRef = $request->get('from_ref');
        $toRef = $request->get('to_ref', 'HEAD');
        $includeAuthors = (bool) $request->get('include_authors', false);

        try {
            $client = app(GitOperationRouter::class)->resolve($repo);

            // Get commits between refs
            $commits = $client->getCommitLog($fromRef, $toRef);

            if (empty($commits)) {
                return Response::text(json_encode([
                    'version' => $version,
                    'markdown' => "## {$version}\n\nNo changes found between the specified refs.",
                    'groups' => [],
                    'commit_count' => 0,
                ]));
            }

            $groups = $this->groupCommits($commits);
            $markdown = $this->renderMarkdown($version, $groups, $includeAuthors);

            return Response::text(json_encode([
                'version' => $version,
                'from_ref' => $fromRef,
                'to_ref' => $toRef,
                'commit_count' => count($commits),
                'markdown' => $markdown,
                'groups' => $groups,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @param  array<int, array{sha: string, message: string, author: string, date: string}>  $commits
     * @return array<string, array<int, array{message: string, sha: string, author: string}>>
     */
    private function groupCommits(array $commits): array
    {
        $typeLabels = [
            'feat' => 'Features',
            'fix' => 'Bug Fixes',
            'perf' => 'Performance',
            'refactor' => 'Refactoring',
            'docs' => 'Documentation',
            'test' => 'Tests',
            'chore' => 'Chores',
            'ci' => 'CI/CD',
            'style' => 'Code Style',
            'build' => 'Build',
            'revert' => 'Reverts',
            'other' => 'Other',
        ];

        $groups = [];

        foreach ($commits as $commit) {
            $message = trim($commit['message'] ?? '');
            [$type, $scope, $summary] = $this->parseConventionalCommit($message);

            $label = $typeLabels[$type] ?? $typeLabels['other'];

            $groups[$label][] = [
                'sha' => substr($commit['sha'] ?? '', 0, 8),
                'scope' => $scope,
                'message' => $summary,
                'author' => $commit['author'] ?? '',
                'type' => $type,
            ];
        }

        // Sort groups by importance
        $order = array_values($typeLabels);
        uksort($groups, fn ($a, $b) => (array_search($a, $order) ?: 99) <=> (array_search($b, $order) ?: 99));

        return $groups;
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function parseConventionalCommit(string $message): array
    {
        // Conventional Commits: type(scope)!: description
        if (preg_match('/^(\w+)(?:\(([^)]+)\))?!?:\s+(.+)$/u', $message, $m)) {
            return [$m[1], $m[2] ?: null, $m[3]];
        }

        return ['other', null, $message];
    }

    /**
     * @param  array<string, array<int, array{sha: string, scope: string|null, message: string, author: string}>>  $groups
     */
    private function renderMarkdown(string $version, array $groups, bool $includeAuthors): string
    {
        $date = date('Y-m-d');
        $lines = ["## {$version} ({$date})", ''];

        if (empty($groups)) {
            $lines[] = 'No changes.';

            return implode("\n", $lines);
        }

        foreach ($groups as $label => $commits) {
            $lines[] = "### {$label}";
            $lines[] = '';

            foreach ($commits as $commit) {
                $scope = $commit['scope'] ? "**{$commit['scope']}**: " : '';
                $sha = $commit['sha'] ? " (`{$commit['sha']}`)" : '';
                $author = $includeAuthors && $commit['author'] ? " — {$commit['author']}" : '';
                $lines[] = "- {$scope}{$commit['message']}{$sha}{$author}";
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }
}
