<?php

namespace App\Infrastructure\Encryption\Kms\Providers;

use App\Infrastructure\Encryption\Kms\Contracts\KmsWrapperInterface;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsAccessDeniedException;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;
use Google\Cloud\Kms\V1\Client\KeyManagementServiceClient;
use Google\Cloud\Kms\V1\DecryptRequest;
use Google\Cloud\Kms\V1\EncryptRequest;

class GcpKmsWrapper implements KmsWrapperInterface
{
    public function wrap(string $plaintextDek, array $config): string
    {
        $client = $this->buildClient($config);
        $keyName = $this->buildKeyName($config);

        try {
            $request = (new EncryptRequest)
                ->setName($keyName)
                ->setPlaintext($plaintextDek);

            $response = $client->encrypt($request);

            return base64_encode($response->getCiphertext());
        } catch (\Google\ApiCore\ApiException $e) {
            $this->handleGcpException($e);
        } finally {
            $client->close();
        }
    }

    public function unwrap(string $wrappedDek, array $config): string
    {
        $client = $this->buildClient($config);
        $keyName = $this->buildKeyName($config);

        try {
            $request = (new DecryptRequest)
                ->setName($keyName)
                ->setCiphertext(base64_decode($wrappedDek));

            $response = $client->decrypt($request);

            return $response->getPlaintext();
        } catch (\Google\ApiCore\ApiException $e) {
            $this->handleGcpException($e);
        } finally {
            $client->close();
        }
    }

    public function test(array $config): bool
    {
        $client = $this->buildClient($config);
        $keyName = $this->buildKeyName($config);

        try {
            $testData = random_bytes(32);

            $encryptRequest = (new EncryptRequest)
                ->setName($keyName)
                ->setPlaintext($testData);
            $encrypted = $client->encrypt($encryptRequest);

            $decryptRequest = (new DecryptRequest)
                ->setName($keyName)
                ->setCiphertext($encrypted->getCiphertext());
            $decrypted = $client->decrypt($decryptRequest);

            return $decrypted->getPlaintext() === $testData;
        } catch (\Google\ApiCore\ApiException $e) {
            $this->handleGcpException($e);
        } finally {
            $client->close();
        }
    }

    public function providerName(): string
    {
        return 'gcp_kms';
    }

    private function buildClient(array $config): KeyManagementServiceClient
    {
        $options = [];

        if (! empty($config['service_account_json'])) {
            $credentials = is_string($config['service_account_json'])
                ? json_decode($config['service_account_json'], true)
                : $config['service_account_json'];
            $options['credentials'] = $credentials;
        }

        return new KeyManagementServiceClient($options);
    }

    private function buildKeyName(array $config): string
    {
        return sprintf(
            'projects/%s/locations/%s/keyRings/%s/cryptoKeys/%s',
            $config['project_id'],
            $config['location'],
            $config['key_ring'],
            $config['key_id'],
        );
    }

    /**
     * @return never
     */
    private function handleGcpException(\Google\ApiCore\ApiException $e): never
    {
        $code = $e->getCode();

        // PERMISSION_DENIED = 7, UNAUTHENTICATED = 16
        if (in_array($code, [7, 16])) {
            throw new KmsAccessDeniedException('gcp_kms', $e->getMessage(), $e);
        }

        throw new KmsUnavailableException('gcp_kms', $e->getMessage(), $e);
    }
}
