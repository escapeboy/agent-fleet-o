<?php

namespace App\Domain\Integration\Drivers\Twitter;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TwitterIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.twitter.com/2';

    public function key(): string
    {
        return 'twitter';
    }

    public function label(): string
    {
        return 'X (Twitter)';
    }

    public function description(): string
    {
        return 'Post tweets, monitor mentions, and engage with conversations on X (Twitter).';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'bearer_token' => [
                'type' => 'password',
                'required' => true,
                'label' => 'Bearer Token',
                'hint' => 'App-only Bearer Token — used for search and read operations.',
            ],
            'api_key' => [
                'type' => 'password',
                'required' => true,
                'label' => 'API Key (Consumer Key)',
                'hint' => 'From developer.twitter.com → App → Keys and Tokens.',
            ],
            'api_secret' => [
                'type' => 'password',
                'required' => true,
                'label' => 'API Key Secret (Consumer Secret)',
            ],
            'access_token' => [
                'type' => 'password',
                'required' => true,
                'label' => 'Access Token',
                'hint' => 'User access token — must be for the account that will post.',
            ],
            'access_token_secret' => [
                'type' => 'password',
                'required' => true,
                'label' => 'Access Token Secret',
            ],
            'username' => [
                'type' => 'string',
                'required' => false,
                'label' => 'Username (@handle)',
                'hint' => 'Used for display only. Example: fleetqnet',
            ],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $bearerToken = $credentials['bearer_token'] ?? null;
        if (! $bearerToken) {
            return false;
        }

        try {
            $response = Http::withToken($bearerToken)
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $bearerToken = $integration->getCredentialSecret('bearer_token');
        if (! $bearerToken) {
            return HealthResult::fail('No bearer token configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($bearerToken)
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $username = $response->json('data.username', 'unknown');

                return HealthResult::ok($latency, "Connected as @{$username}");
            }

            return HealthResult::fail($response->json('detail') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition(
                'mention_received',
                'Mention Received',
                'The account was mentioned in a tweet.',
            ),
            new TriggerDefinition(
                'keyword_match',
                'Keyword Matched',
                'A tweet matching the configured search query was found.',
            ),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('post_tweet', 'Post Tweet', 'Publish a new tweet.', [
                'text' => ['type' => 'string', 'required' => true, 'label' => 'Tweet text (max 280 characters)'],
            ]),
            new ActionDefinition('reply_to_tweet', 'Reply to Tweet', 'Reply to an existing tweet.', [
                'tweet_id' => ['type' => 'string', 'required' => true, 'label' => 'Tweet ID to reply to'],
                'text' => ['type' => 'string', 'required' => true, 'label' => 'Reply text (max 280 characters)'],
            ]),
            new ActionDefinition('like_tweet', 'Like Tweet', 'Like a tweet.', [
                'tweet_id' => ['type' => 'string', 'required' => true, 'label' => 'Tweet ID to like'],
            ]),
            new ActionDefinition('search_tweets', 'Search Tweets', 'Search for recent tweets matching a query.', [
                'query' => ['type' => 'string', 'required' => true, 'label' => 'Search query (Twitter search syntax)'],
                'max_results' => ['type' => 'integer', 'required' => false, 'label' => 'Max results (10–100, default 10)'],
            ]),
            new ActionDefinition('get_mentions', 'Get Mentions', 'Retrieve recent mentions of the authenticated account.', [
                'max_results' => ['type' => 'integer', 'required' => false, 'label' => 'Max results (5–100, default 10)'],
                'since_id' => ['type' => 'string', 'required' => false, 'label' => 'Only return tweets newer than this ID'],
            ]),
            new ActionDefinition('get_user_timeline', 'Get User Timeline', 'Retrieve recent tweets by the authenticated account.', [
                'max_results' => ['type' => 'integer', 'required' => false, 'label' => 'Max results (5–100, default 10)'],
            ]),
            new ActionDefinition('retweet', 'Retweet', 'Retweet a tweet.', [
                'tweet_id' => ['type' => 'string', 'required' => true, 'label' => 'Tweet ID to retweet'],
            ]),
        ];
    }

    /** Poll for mentions and keyword matches every 5 minutes. */
    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        $bearerToken = $integration->getCredentialSecret('bearer_token');
        if (! $bearerToken) {
            return [];
        }

        $signals = [];

        // Poll mentions
        $signals = array_merge($signals, $this->pollMentions($integration, $bearerToken));

        // Poll keyword matches if a query is configured
        $keywordQuery = $integration->config['poll_query'] ?? null;
        if ($keywordQuery) {
            $signals = array_merge($signals, $this->pollKeywords($integration, $bearerToken, $keywordQuery));
        }

        return $signals;
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
        $bearerToken = $integration->getCredentialSecret('bearer_token');
        $apiKey = $integration->getCredentialSecret('api_key');
        $apiSecret = $integration->getCredentialSecret('api_secret');
        $accessToken = $integration->getCredentialSecret('access_token');
        $accessTokenSecret = $integration->getCredentialSecret('access_token_secret');

        return match ($action) {
            'post_tweet' => $this->postTweet($apiKey, $apiSecret, $accessToken, $accessTokenSecret, $params['text']),
            'reply_to_tweet' => $this->replyToTweet($apiKey, $apiSecret, $accessToken, $accessTokenSecret, $params['tweet_id'], $params['text']),
            'like_tweet' => $this->likeTweet($bearerToken, $apiKey, $apiSecret, $accessToken, $accessTokenSecret, $params['tweet_id']),
            'search_tweets' => $this->searchTweets($bearerToken, $params['query'], (int) ($params['max_results'] ?? 10)),
            'get_mentions' => $this->getMentions($bearerToken, (int) ($params['max_results'] ?? 10), $params['since_id'] ?? null),
            'get_user_timeline' => $this->getUserTimeline($bearerToken, (int) ($params['max_results'] ?? 10)),
            'retweet' => $this->retweet($apiKey, $apiSecret, $accessToken, $accessTokenSecret, $params['tweet_id']),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    // -------------------------------------------------------------------------
    // Private — Write operations (OAuth 1.0a User Context)
    // -------------------------------------------------------------------------

    private function postTweet(?string $apiKey, ?string $apiSecret, ?string $accessToken, ?string $accessTokenSecret, string $text): array
    {
        $url = self::API_BASE.'/tweets';
        $body = ['text' => $text];

        return Http::withHeaders($this->oauth1Headers('POST', $url, [], $apiKey, $apiSecret, $accessToken, $accessTokenSecret))
            ->post($url, $body)
            ->json();
    }

    private function replyToTweet(?string $apiKey, ?string $apiSecret, ?string $accessToken, ?string $accessTokenSecret, string $tweetId, string $text): array
    {
        $url = self::API_BASE.'/tweets';
        $body = ['text' => $text, 'reply' => ['in_reply_to_tweet_id' => $tweetId]];

        return Http::withHeaders($this->oauth1Headers('POST', $url, [], $apiKey, $apiSecret, $accessToken, $accessTokenSecret))
            ->post($url, $body)
            ->json();
    }

    private function likeTweet(?string $bearerToken, ?string $apiKey, ?string $apiSecret, ?string $accessToken, ?string $accessTokenSecret, string $tweetId): array
    {
        // Resolve authenticated user ID first (use bearer token for lookup)
        $meResponse = Http::withToken($bearerToken)->get(self::API_BASE.'/users/me');
        $userId = $meResponse->json('data.id');
        if (! $userId) {
            return ['error' => 'Could not resolve authenticated user ID'];
        }

        $url = self::API_BASE."/users/{$userId}/likes";
        $body = ['tweet_id' => $tweetId];

        return Http::withHeaders($this->oauth1Headers('POST', $url, [], $apiKey, $apiSecret, $accessToken, $accessTokenSecret))
            ->post($url, $body)
            ->json();
    }

    private function retweet(?string $apiKey, ?string $apiSecret, ?string $accessToken, ?string $accessTokenSecret, string $tweetId): array
    {
        // Need user ID — fetch it with OAuth 1.0a
        $meUrl = self::API_BASE.'/users/me';
        $meResponse = Http::withHeaders($this->oauth1Headers('GET', $meUrl, [], $apiKey, $apiSecret, $accessToken, $accessTokenSecret))
            ->get($meUrl);
        $userId = $meResponse->json('data.id');
        if (! $userId) {
            return ['error' => 'Could not resolve authenticated user ID'];
        }

        $url = self::API_BASE."/users/{$userId}/retweets";
        $body = ['tweet_id' => $tweetId];

        return Http::withHeaders($this->oauth1Headers('POST', $url, [], $apiKey, $apiSecret, $accessToken, $accessTokenSecret))
            ->post($url, $body)
            ->json();
    }

    // -------------------------------------------------------------------------
    // Private — Read operations (Bearer Token / App-only)
    // -------------------------------------------------------------------------

    private function searchTweets(?string $bearerToken, string $query, int $maxResults = 10): array
    {
        $maxResults = max(10, min(100, $maxResults));

        return Http::withToken($bearerToken)
            ->get(self::API_BASE.'/tweets/search/recent', [
                'query' => $query,
                'max_results' => $maxResults,
                'tweet.fields' => 'author_id,created_at,text,public_metrics',
                'expansions' => 'author_id',
                'user.fields' => 'username,name',
            ])
            ->json();
    }

    private function getMentions(?string $bearerToken, int $maxResults = 10, ?string $sinceId = null): array
    {
        $meResponse = Http::withToken($bearerToken)->get(self::API_BASE.'/users/me');
        $userId = $meResponse->json('data.id');
        if (! $userId) {
            return ['error' => 'Could not resolve authenticated user ID'];
        }

        $maxResults = max(5, min(100, $maxResults));
        $params = [
            'max_results' => $maxResults,
            'tweet.fields' => 'author_id,created_at,text,public_metrics',
            'expansions' => 'author_id',
            'user.fields' => 'username,name',
        ];
        if ($sinceId) {
            $params['since_id'] = $sinceId;
        }

        return Http::withToken($bearerToken)
            ->get(self::API_BASE."/users/{$userId}/mentions", $params)
            ->json();
    }

    private function getUserTimeline(?string $bearerToken, int $maxResults = 10): array
    {
        $meResponse = Http::withToken($bearerToken)->get(self::API_BASE.'/users/me');
        $userId = $meResponse->json('data.id');
        if (! $userId) {
            return ['error' => 'Could not resolve authenticated user ID'];
        }

        $maxResults = max(5, min(100, $maxResults));

        return Http::withToken($bearerToken)
            ->get(self::API_BASE."/users/{$userId}/tweets", [
                'max_results' => $maxResults,
                'tweet.fields' => 'created_at,text,public_metrics',
            ])
            ->json();
    }

    // -------------------------------------------------------------------------
    // Private — Polling helpers
    // -------------------------------------------------------------------------

    private function pollMentions(Integration $integration, string $bearerToken): array
    {
        $cacheKey = "twitter_poll_mention_since:{$integration->id}";
        $sinceId = Cache::get($cacheKey);

        $meResponse = Http::withToken($bearerToken)->timeout(10)->get(self::API_BASE.'/users/me');
        $userId = $meResponse->json('data.id');
        if (! $userId) {
            return [];
        }

        $params = [
            'max_results' => 10,
            'tweet.fields' => 'author_id,created_at,text',
            'expansions' => 'author_id',
            'user.fields' => 'username,name',
        ];
        if ($sinceId) {
            $params['since_id'] = $sinceId;
        }

        try {
            $response = Http::withToken($bearerToken)
                ->timeout(15)
                ->get(self::API_BASE."/users/{$userId}/mentions", $params);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $tweets = $data['data'] ?? [];

            if (empty($tweets)) {
                return [];
            }

            // Store newest tweet ID for next poll
            Cache::put($cacheKey, $tweets[0]['id'], now()->addHours(24));

            return array_map(fn (array $tweet) => [
                'source_type' => 'twitter',
                'source_id' => $tweet['id'],
                'payload' => $tweet,
                'tags' => ['twitter', 'mention'],
            ], $tweets);
        } catch (\Throwable) {
            return [];
        }
    }

    private function pollKeywords(Integration $integration, string $bearerToken, string $query): array
    {
        $cacheKey = "twitter_poll_kw_since:{$integration->id}";
        $sinceId = Cache::get($cacheKey);

        $params = [
            'query' => $query.' -is:retweet lang:en',
            'max_results' => 10,
            'tweet.fields' => 'author_id,created_at,text',
            'expansions' => 'author_id',
            'user.fields' => 'username,name',
        ];
        if ($sinceId) {
            $params['since_id'] = $sinceId;
        }

        try {
            $response = Http::withToken($bearerToken)
                ->timeout(15)
                ->get(self::API_BASE.'/tweets/search/recent', $params);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $tweets = $data['data'] ?? [];

            if (empty($tweets)) {
                return [];
            }

            Cache::put($cacheKey, $tweets[0]['id'], now()->addHours(24));

            return array_map(fn (array $tweet) => [
                'source_type' => 'twitter',
                'source_id' => $tweet['id'],
                'payload' => $tweet,
                'tags' => ['twitter', 'keyword_match'],
            ], $tweets);
        } catch (\Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Private — OAuth 1.0a signature (required for write operations)
    // -------------------------------------------------------------------------

    /**
     * Build OAuth 1.0a Authorization headers for user-context write requests.
     *
     * @param  array<string, string>  $extraOauthParams
     * @return array<string, string>
     */
    private function oauth1Headers(
        string $method,
        string $url,
        array $extraOauthParams,
        ?string $apiKey,
        ?string $apiSecret,
        ?string $accessToken,
        ?string $accessTokenSecret,
    ): array {
        $oauthParams = array_merge([
            'oauth_consumer_key' => $apiKey ?? '',
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $accessToken ?? '',
            'oauth_version' => '1.0',
        ], $extraOauthParams);

        $signature = $this->buildOauth1Signature($method, $url, $oauthParams, $apiSecret ?? '', $accessTokenSecret ?? '');
        $oauthParams['oauth_signature'] = $signature;

        ksort($oauthParams);
        $headerParts = array_map(
            fn ($k, $v) => rawurlencode($k).'="'.rawurlencode($v).'"',
            array_keys($oauthParams),
            array_values($oauthParams),
        );

        return [
            'Authorization' => 'OAuth '.implode(', ', $headerParts),
            'Content-Type' => 'application/json',
        ];
    }

    private function buildOauth1Signature(
        string $method,
        string $url,
        array $oauthParams,
        string $apiSecret,
        string $accessTokenSecret,
    ): string {
        // Sort all parameters and build the parameter string
        $allParams = $oauthParams;
        ksort($allParams);

        $paramString = implode('&', array_map(
            fn ($k, $v) => rawurlencode($k).'='.rawurlencode($v),
            array_keys($allParams),
            array_values($allParams),
        ));

        // Build signature base string
        $baseString = strtoupper($method).'&'.rawurlencode($url).'&'.rawurlencode($paramString);

        // Build signing key
        $signingKey = rawurlencode($apiSecret).'&'.rawurlencode($accessTokenSecret);

        return base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
    }
}
