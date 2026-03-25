<?php

namespace App\Domain\Integration\Drivers\LinkedIn;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn integration driver (OAuth2).
 *
 * Supports publishing posts and comments to LinkedIn member profiles and
 * organisation pages via the LinkedIn Posts API and Social Actions API.
 *
 * Access tiers:
 *   - w_member_social: self-service ("Share on LinkedIn" product)
 *   - w_organization_social, w_member_social_feed, w_organization_social_feed:
 *     require LinkedIn Community Management API approval (vetted partner programme).
 *
 * LinkedIn API version: 202603
 * Docs: https://learn.microsoft.com/en-us/linkedin/marketing/community-management/
 */
class LinkedInIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.linkedin.com';

    private const API_VERSION = '202603';

    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn';
    }

    public function description(): string
    {
        return 'Publish posts and comments to LinkedIn member profiles and company pages from agent pipelines.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'password', 'required' => true,  'label' => 'Access Token'],
            'refresh_token' => ['type' => 'password', 'required' => false, 'label' => 'Refresh Token'],
            'token_expires_at' => ['type' => 'string',   'required' => false, 'label' => 'Token Expiry (ISO 8601)'],
            'person_urn' => ['type' => 'string',   'required' => false, 'label' => 'Member URN (urn:li:person:{id})'],
            'name' => ['type' => 'string',   'required' => false, 'label' => 'Member Name'],
            'email' => ['type' => 'string',   'required' => false, 'label' => 'Member Email'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;

        if (empty($token)) {
            return false;
        }

        $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/v2/userinfo');

        return $response->successful();
    }

    public function ping(Integration $integration): HealthResult
    {
        try {
            $token = $this->resolveAccessToken($integration);
        } catch (\Throwable $e) {
            return HealthResult::fail('Token refresh failed: '.$e->getMessage());
        }

        $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/v2/userinfo');

        if (! $response->successful()) {
            return HealthResult::fail('LinkedIn API returned '.$response->status());
        }

        return HealthResult::ok(0);
    }

    public function triggers(): array
    {
        return [];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('get_profile', 'Get Member Profile', 'Retrieve the authenticated member\'s profile, URN, and email.', []),

            new ActionDefinition('get_organizations', 'List Organisations', 'List LinkedIn Pages (organisations) where the authenticated member is an administrator.', []),

            new ActionDefinition('post_text', 'Publish Text Post', 'Publish a text post to a LinkedIn member profile or company page.', [
                'text' => ['type' => 'string', 'required' => true,  'label' => 'Post text'],
                'author_type' => ['type' => 'string', 'required' => false, 'label' => 'Author: member (default) or organization'],
                'organization_id' => ['type' => 'string', 'required' => false, 'label' => 'Organization ID (required when author_type=organization)'],
                'visibility' => ['type' => 'string', 'required' => false, 'label' => 'Visibility: PUBLIC (default) or CONNECTIONS'],
            ]),

            new ActionDefinition('post_link', 'Publish Link Post', 'Publish a post with a link preview to a LinkedIn member profile or company page.', [
                'text' => ['type' => 'string', 'required' => true,  'label' => 'Post text'],
                'url' => ['type' => 'string', 'required' => true,  'label' => 'URL to share'],
                'author_type' => ['type' => 'string', 'required' => false, 'label' => 'Author: member (default) or organization'],
                'organization_id' => ['type' => 'string', 'required' => false, 'label' => 'Organization ID (required when author_type=organization)'],
                'visibility' => ['type' => 'string', 'required' => false, 'label' => 'Visibility: PUBLIC (default) or CONNECTIONS'],
            ]),

            new ActionDefinition('comment', 'Comment on Post', 'Add a comment to an existing LinkedIn post, identified by its URN or URL.', [
                'text' => ['type' => 'string', 'required' => true, 'label' => 'Comment text'],
                'post_urn' => ['type' => 'string', 'required' => false, 'label' => 'LinkedIn post URN (e.g. urn:li:share:123456789)'],
                'post_url' => ['type' => 'string', 'required' => false, 'label' => 'LinkedIn post URL (used to extract the URN when post_urn is not provided)'],
                'author_type' => ['type' => 'string', 'required' => false, 'label' => 'Author: member (default) or organization'],
                'organization_id' => ['type' => 'string', 'required' => false, 'label' => 'Organization ID (required when author_type=organization)'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0;
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $this->resolveAccessToken($integration);

        return match ($action) {
            'get_profile' => $this->getProfile($token),
            'get_organizations' => $this->getOrganizations($token),
            'post_text' => $this->postText($token, $integration, $params),
            'post_link' => $this->postLink($token, $integration, $params),
            'comment' => $this->comment($token, $integration, $params),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    // -------------------------------------------------------------------------
    // Private action implementations
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function getProfile(string $token): array
    {
        $response = Http::withToken($token)->timeout(15)->get(self::API_BASE.'/v2/userinfo');

        abort_unless($response->successful(), 422, 'LinkedIn /v2/userinfo failed: '.$response->body());

        $data = $response->json();
        $sub = $data['sub'] ?? null;

        return [
            'person_urn' => $sub ? 'urn:li:person:'.$sub : null,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'picture' => $data['picture'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getOrganizations(string $token): array
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->get(self::API_BASE.'/v2/organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(*,organization~(id,localizedName)))',
            ]);

        if (! $response->successful()) {
            return [];
        }

        $organisations = [];
        foreach ($response->json('elements', []) as $element) {
            $org = $element['organization~'] ?? [];
            $orgId = $org['id'] ?? null;
            if ($orgId) {
                $organisations[] = [
                    'id' => (string) $orgId,
                    'urn' => 'urn:li:organization:'.$orgId,
                    'name' => $org['localizedName'] ?? 'Unknown',
                ];
            }
        }

        return $organisations;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function postText(string $token, Integration $integration, array $params): array
    {
        $author = $this->resolveAuthorUrn($integration, $params);
        $visibility = $this->resolveVisibility($params);

        $body = [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $params['text']],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => $visibility],
        ];

        return $this->callPostsApi($token, $body);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function postLink(string $token, Integration $integration, array $params): array
    {
        $author = $this->resolveAuthorUrn($integration, $params);
        $visibility = $this->resolveVisibility($params);

        $body = [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $params['text']],
                    'shareMediaCategory' => 'ARTICLE',
                    'media' => [[
                        'status' => 'READY',
                        'originalUrl' => $params['url'],
                    ]],
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => $visibility],
        ];

        return $this->callPostsApi($token, $body);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function comment(string $token, Integration $integration, array $params): array
    {
        $postUrn = $params['post_urn'] ?? null;

        if (! $postUrn && ! empty($params['post_url'])) {
            $postUrn = $this->extractUrnFromUrl($params['post_url']);
        }

        abort_unless($postUrn, 422, 'LinkedIn comment requires either post_urn or post_url.');

        $actor = $this->resolveAuthorUrn($integration, $params);

        $body = [
            'actor' => $actor,
            'message' => ['text' => $params['text']],
        ];

        $encodedUrn = rawurlencode($postUrn);
        $response = Http::withToken($token)
            ->withHeaders($this->linkedinHeaders())
            ->timeout(15)
            ->post(self::API_BASE."/rest/socialActions/{$encodedUrn}/comments", $body);

        abort_unless($response->successful(), 422, 'LinkedIn comment failed: '.$response->body());

        return $response->json() ?? [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Call the LinkedIn UGC Posts API (restli 2.0.0).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function callPostsApi(string $token, array $body): array
    {
        $response = Http::withToken($token)
            ->withHeaders($this->linkedinHeaders())
            ->timeout(15)
            ->post(self::API_BASE.'/v2/ugcPosts', $body);

        abort_unless($response->successful(), 422, 'LinkedIn post failed: '.$response->body());

        return [
            'id' => $response->header('X-RestLi-Id') ?? $response->json('id') ?? null,
            'status' => $response->status(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function linkedinHeaders(): array
    {
        return [
            'LinkedIn-Version' => self::API_VERSION,
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    /**
     * Resolve the author URN from integration credentials or params.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveAuthorUrn(Integration $integration, array $params): string
    {
        $authorType = $params['author_type'] ?? 'member';

        if ($authorType === 'organization') {
            $orgId = $params['organization_id'] ?? null;
            abort_unless($orgId, 422, 'organization_id is required when author_type is organization.');

            return 'urn:li:organization:'.$orgId;
        }

        $personUrn = $integration->credential?->secret_data['person_urn'] ?? null;
        abort_unless($personUrn, 422, 'LinkedIn person URN not found in credentials. Please reconnect the integration.');

        return $personUrn;
    }

    /**
     * Resolve visibility from params, defaulting to PUBLIC.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveVisibility(array $params): string
    {
        $visibility = strtoupper($params['visibility'] ?? 'PUBLIC');

        return in_array($visibility, ['PUBLIC', 'CONNECTIONS'], true) ? $visibility : 'PUBLIC';
    }

    /**
     * Attempt to extract a LinkedIn post URN from a post URL.
     *
     * LinkedIn post URLs typically follow:
     *   https://www.linkedin.com/posts/username_slug-{shareId}-activity
     *   https://www.linkedin.com/feed/update/urn:li:share:{id}
     *   https://www.linkedin.com/feed/update/urn:li:ugcPost:{id}
     */
    private function extractUrnFromUrl(string $url): ?string
    {
        // feed/update/urn:li:share:{id} or feed/update/urn:li:ugcPost:{id}
        if (preg_match('#/feed/update/(urn:li:[^?/]+)#i', $url, $m)) {
            return urldecode($m[1]);
        }

        // /posts/...-{activityId}-activity — map to urn:li:activity
        if (preg_match('#-(\d{19,})-activity#', $url, $m)) {
            return 'urn:li:activity:'.$m[1];
        }

        return null;
    }

    /**
     * Returns a valid access token, refreshing if it is expired or about to expire.
     */
    private function resolveAccessToken(Integration $integration): string
    {
        $creds = $integration->credential?->secret_data ?? [];
        $expiresAt = $creds['token_expires_at'] ?? null;
        $accessToken = $creds['access_token'] ?? null;

        if ($accessToken && (! $expiresAt || Carbon::parse($expiresAt)->gt(now()->addMinutes(5)))) {
            return $accessToken;
        }

        $refreshToken = $creds['refresh_token'] ?? null;
        abort_unless($refreshToken, 422, 'LinkedIn access token expired and no refresh token available.');

        $response = Http::asForm()->timeout(15)->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => config('integrations.oauth.linkedin.client_id'),
            'client_secret' => config('integrations.oauth.linkedin.client_secret'),
        ]);

        abort_unless($response->successful(), 422, 'LinkedIn token refresh failed: '.$response->body());

        $newCreds = array_merge($creds, [
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds($response->json('expires_in', 5183999))->toIso8601String(),
        ]);

        if ($response->json('refresh_token')) {
            $newCreds['refresh_token'] = $response->json('refresh_token');
        }

        if ($integration->credential) {
            $integration->credential->update(['secret_data' => $newCreds]);
        }

        return $newCreds['access_token'];
    }
}
