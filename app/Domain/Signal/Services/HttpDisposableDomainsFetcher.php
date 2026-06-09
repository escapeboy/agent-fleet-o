<?php

namespace App\Domain\Signal\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Propaganistas\LaravelDisposableEmail\Contracts\Fetcher;
use UnexpectedValueException;

/**
 * Replaces the package's DefaultFetcher, which uses file_get_contents()
 * and breaks on hosts with allow_url_fopen=0 (production FPM).
 */
class HttpDisposableDomainsFetcher implements Fetcher
{
    public function handle($url): array
    {
        if (! $url) {
            throw new InvalidArgumentException('Source URL is null');
        }

        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            throw new UnexpectedValueException('Failed to fetch the source URL ('.$url.')');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new UnexpectedValueException('Provided data could not be parsed as JSON');
        }

        return $data;
    }
}
