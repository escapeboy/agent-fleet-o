<?php

namespace App\Infrastructure\Encryption\Kms\Providers;

use App\Infrastructure\Encryption\Kms\Contracts\KmsWrapperInterface;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsAccessDeniedException;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AzureKeyVaultWrapper implements KmsWrapperInterface
{
    public function wrap(string $plaintextDek, array $config): string
    {
        $token = $this->getAccessToken($config);
        $keyUrl = $this->buildKeyUrl($config);

        $response = Http::withToken($token)
            ->timeout(10)
            ->post("{$keyUrl}/wrapkey?api-version=7.4", [
                'alg' => 'RSA-OAEP-256',
                'value' => rtrim(strtr(base64_encode($plaintextDek), '+/', '-_'), '='),
            ]);

        if (! $response->successful()) {
            $this->handleErrorResponse($response);
        }

        return $response->json('value');
    }

    public function unwrap(string $wrappedDek, array $config): string
    {
        $token = $this->getAccessToken($config);
        $keyUrl = $this->buildKeyUrl($config);

        $response = Http::withToken($token)
            ->timeout(10)
            ->post("{$keyUrl}/unwrapkey?api-version=7.4", [
                'alg' => 'RSA-OAEP-256',
                'value' => $wrappedDek,
            ]);

        if (! $response->successful()) {
            $this->handleErrorResponse($response);
        }

        // Azure returns base64url-encoded plaintext
        $base64Url = $response->json('value');

        return base64_decode(strtr($base64Url, '-_', '+/'));
    }

    public function test(array $config): bool
    {
        $token = $this->getAccessToken($config);
        $keyUrl = $this->buildKeyUrl($config);

        $testData = random_bytes(32);
        $encoded = rtrim(strtr(base64_encode($testData), '+/', '-_'), '=');

        $wrapResponse = Http::withToken($token)
            ->timeout(10)
            ->post("{$keyUrl}/wrapkey?api-version=7.4", [
                'alg' => 'RSA-OAEP-256',
                'value' => $encoded,
            ]);

        if (! $wrapResponse->successful()) {
            $this->handleErrorResponse($wrapResponse);
        }

        $unwrapResponse = Http::withToken($token)
            ->timeout(10)
            ->post("{$keyUrl}/unwrapkey?api-version=7.4", [
                'alg' => 'RSA-OAEP-256',
                'value' => $wrapResponse->json('value'),
            ]);

        if (! $unwrapResponse->successful()) {
            $this->handleErrorResponse($unwrapResponse);
        }

        $decrypted = base64_decode(strtr($unwrapResponse->json('value'), '-_', '+/'));

        return $decrypted === $testData;
    }

    public function providerName(): string
    {
        return 'azure_key_vault';
    }

    private function getAccessToken(array $config): string
    {
        $response = Http::asForm()
            ->timeout(10)
            ->post("https://login.microsoftonline.com/{$config['tenant_id']}/oauth2/v2.0/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'scope' => 'https://vault.azure.net/.default',
            ]);

        if (! $response->successful()) {
            Log::warning('Azure AD token request failed', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            throw new KmsAccessDeniedException(
                'azure_key_vault',
                'Failed to obtain Azure AD token: ' . ($response->json('error_description') ?? 'unknown error'),
            );
        }

        return $response->json('access_token');
    }

    private function buildKeyUrl(array $config): string
    {
        $vaultUrl = rtrim($config['vault_url'], '/');
        $keyPath = "/keys/{$config['key_name']}";

        if (! empty($config['key_version'])) {
            $keyPath .= "/{$config['key_version']}";
        }

        return $vaultUrl . $keyPath;
    }

    /**
     * @return never
     */
    private function handleErrorResponse(\Illuminate\Http\Client\Response $response): never
    {
        $status = $response->status();
        $error = $response->json('error.message') ?? $response->body();

        if (in_array($status, [401, 403])) {
            throw new KmsAccessDeniedException('azure_key_vault', $error);
        }

        throw new KmsUnavailableException('azure_key_vault', "HTTP {$status}: {$error}");
    }
}
