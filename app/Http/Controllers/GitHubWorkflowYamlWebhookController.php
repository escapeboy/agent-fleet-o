<?php

namespace App\Http\Controllers;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Jobs\ImportWorkflowFromYamlJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GitHub webhook handler for the reverse Workflow YAML git sync flow.
 *
 * Forward sync: Workflow → YAML → committed via GatedGitClient (existing).
 * Reverse sync (this): PR-merged with workflows/*.yaml in diff → fetch YAML at HEAD → ImportWorkflowAction.
 *
 * The webhook payload comes from a GitHub repo configured to send `pull_request` events.
 * Signing: HMAC-SHA256 with the secret stored on the GitRepository's webhook config.
 *
 * On-the-wire identification: the team is identified by a `team_id` in the webhook URL.
 */
class GitHubWorkflowYamlWebhookController extends Controller
{
    public function __invoke(Request $request, string $teamId): JsonResponse
    {
        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            return response()->json(['error' => 'team not found'], 404);
        }

        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        $secret = $this->resolveSecret($team);
        if ($secret === null) {
            return response()->json(['error' => 'webhook secret not configured for this team'], 400);
        }

        if (! $this->verifySignature($rawBody, $signature, $secret)) {
            Log::warning('GitHub workflow yaml webhook: invalid signature', ['team_id' => $teamId]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        if ($event !== 'pull_request') {
            return response()->json(['ok' => true, 'skipped' => 'non-pull_request event']);
        }

        $payload = $request->input();
        if (($payload['action'] ?? null) !== 'closed' || ($payload['pull_request']['merged'] ?? false) !== true) {
            return response()->json(['ok' => true, 'skipped' => 'not a merge']);
        }

        $repoFullName = (string) ($payload['repository']['full_name'] ?? '');
        $headSha = (string) ($payload['pull_request']['merge_commit_sha'] ?? $payload['pull_request']['head']['sha'] ?? '');
        if ($repoFullName === '' || $headSha === '') {
            return response()->json(['error' => 'missing repository or head sha'], 422);
        }

        $changedFiles = $this->fetchChangedFiles($repoFullName, (int) $payload['pull_request']['number']);
        $yamlFiles = array_values(array_filter(
            $changedFiles,
            fn (string $f) => preg_match('#^workflows/[^/]+\.ya?ml$#', $f) === 1,
        ));

        if ($yamlFiles === []) {
            return response()->json(['ok' => true, 'skipped' => 'no workflow yaml files in diff']);
        }

        $owner = $this->ownerIdFor($team);
        $dispatched = [];
        foreach ($yamlFiles as $path) {
            $yaml = $this->fetchFileContent($repoFullName, $path, $headSha);
            if ($yaml === null) {
                continue;
            }
            ImportWorkflowFromYamlJob::dispatch(
                teamId: $team->id,
                userId: $owner,
                yaml: $yaml,
                sourceRef: "{$repoFullName}@{$headSha}:{$path}",
            );
            $dispatched[] = $path;
        }

        return response()->json([
            'ok' => true,
            'dispatched' => $dispatched,
        ]);
    }

    private function resolveSecret(Team $team): ?string
    {
        $secret = $team->git_webhook_secret ?? null;
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $fallback = config('github.workflow_webhook_secret');
        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        return null;
    }

    private function verifySignature(string $rawBody, string $headerValue, string $secret): bool
    {
        if (! str_starts_with($headerValue, 'sha256=')) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $headerValue);
    }

    /**
     * @return array<int, string>
     */
    private function fetchChangedFiles(string $repoFullName, int $prNumber): array
    {
        $token = config('github.api_token');
        $headers = is_string($token) && $token !== '' ? ['Authorization' => 'Bearer '.$token] : [];

        try {
            $response = Http::timeout(15)
                ->withHeaders(array_merge(['Accept' => 'application/vnd.github+json'], $headers))
                ->get("https://api.github.com/repos/{$repoFullName}/pulls/{$prNumber}/files", ['per_page' => 100]);
        } catch (\Throwable $e) {
            Log::warning('GitHub PR files fetch failed', ['repo' => $repoFullName, 'pr' => $prNumber, 'error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return array_map(fn ($f) => (string) ($f['filename'] ?? ''), (array) $response->json());
    }

    private function fetchFileContent(string $repoFullName, string $path, string $ref): ?string
    {
        $token = config('github.api_token');
        $headers = is_string($token) && $token !== '' ? ['Authorization' => 'Bearer '.$token] : [];

        try {
            $response = Http::timeout(15)
                ->withHeaders(array_merge(['Accept' => 'application/vnd.github.raw'], $headers))
                ->get("https://api.github.com/repos/{$repoFullName}/contents/{$path}", ['ref' => $ref]);
        } catch (\Throwable $e) {
            Log::warning('GitHub file content fetch failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    private function ownerIdFor(Team $team): string
    {
        return (string) $team->owner_id;
    }
}
