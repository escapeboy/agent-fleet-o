<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Models\GitRepository;

/**
 * Builds an authenticated HTTPS clone URL for a private repository by injecting
 * the repo's stored credential token into the URL userinfo.
 *
 * The returned URL contains a secret — callers MUST keep it in memory only and
 * never log it or persist it. Returns null when the repo has no usable
 * credential (public repos clone fine from the bare url).
 */
class GitCloneUrlResolver
{
    public function authenticatedUrl(GitRepository $repo): ?string
    {
        $url = (string) $repo->url;
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            // SSH or empty url: leave auth to the environment (deploy key/agent).
            return null;
        }

        $token = $this->token($repo);
        if ($token === null || $token === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = $parts['host'];
        $path = $parts['path'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        // GitLab wants the oauth2 username; GitHub (and most others) accept any
        // username with the token as the password.
        $user = str_contains($host, 'gitlab') ? 'oauth2' : 'x-access-token';

        return 'https://'.$user.':'.rawurlencode($token).'@'.$host.$port.$path;
    }

    private function token(GitRepository $repo): ?string
    {
        // data_get (not ->credential->secret_data) so larastan doesn't trip on
        // the generic Model relation type; the TeamEncryptedArray cast still runs.
        $secrets = data_get($repo->credential, 'secret_data');
        if (! is_array($secrets)) {
            return null;
        }

        return $secrets['token']
            ?? $secrets['access_token']
            ?? $secrets['api_key']
            ?? $secrets['password']
            ?? null;
    }
}
