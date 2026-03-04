<?php

namespace App\Infrastructure\Encryption\Kms\Providers;

use App\Infrastructure\Encryption\Kms\Contracts\KmsWrapperInterface;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsAccessDeniedException;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;
use Aws\Kms\KmsClient;
use Aws\Sts\StsClient;
use Illuminate\Support\Facades\Log;

class AwsKmsWrapper implements KmsWrapperInterface
{
    public function wrap(string $plaintextDek, array $config): string
    {
        $client = $this->buildKmsClient($config);

        try {
            $result = $client->encrypt([
                'KeyId' => $config['key_arn'],
                'Plaintext' => $plaintextDek,
            ]);

            return base64_encode($result['CiphertextBlob']);
        } catch (\Aws\Exception\AwsException $e) {
            $this->handleAwsException($e);
        }
    }

    public function unwrap(string $wrappedDek, array $config): string
    {
        $client = $this->buildKmsClient($config);

        try {
            $result = $client->decrypt([
                'KeyId' => $config['key_arn'],
                'CiphertextBlob' => base64_decode($wrappedDek),
            ]);

            return $result['Plaintext'];
        } catch (\Aws\Exception\AwsException $e) {
            $this->handleAwsException($e);
        }
    }

    public function test(array $config): bool
    {
        $client = $this->buildKmsClient($config);

        try {
            $testData = random_bytes(32);

            $encrypted = $client->encrypt([
                'KeyId' => $config['key_arn'],
                'Plaintext' => $testData,
            ]);

            $decrypted = $client->decrypt([
                'KeyId' => $config['key_arn'],
                'CiphertextBlob' => $encrypted['CiphertextBlob'],
            ]);

            return $decrypted['Plaintext'] === $testData;
        } catch (\Aws\Exception\AwsException $e) {
            $this->handleAwsException($e);
        }
    }

    public function providerName(): string
    {
        return 'aws_kms';
    }

    private function buildKmsClient(array $config): KmsClient
    {
        $credentials = $this->assumeRole($config);

        return new KmsClient([
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
            'credentials' => $credentials,
        ]);
    }

    private function assumeRole(array $config): array
    {
        $stsParams = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
        ];

        // Use platform credentials (from env/IAM role) to assume the customer's role
        $sts = new StsClient($stsParams);

        try {
            $assumeParams = [
                'RoleArn' => $config['role_arn'],
                'RoleSessionName' => 'fleetq-kms-' . substr(md5($config['role_arn']), 0, 8),
                'DurationSeconds' => 900, // 15 min minimum
            ];

            if (! empty($config['external_id'])) {
                $assumeParams['ExternalId'] = $config['external_id'];
            }

            $result = $sts->assumeRole($assumeParams);

            return [
                'key' => $result['Credentials']['AccessKeyId'],
                'secret' => $result['Credentials']['SecretAccessKey'],
                'token' => $result['Credentials']['SessionToken'],
            ];
        } catch (\Aws\Exception\AwsException $e) {
            Log::warning('AWS STS AssumeRole failed', [
                'role_arn' => $config['role_arn'],
                'error' => $e->getAwsErrorCode(),
            ]);

            throw new KmsAccessDeniedException(
                'aws_kms',
                "AssumeRole failed: {$e->getAwsErrorMessage()}. Verify your trust policy includes the External ID.",
                $e,
            );
        }
    }

    /**
     * @return never
     */
    private function handleAwsException(\Aws\Exception\AwsException $e): never
    {
        $code = $e->getAwsErrorCode();

        if (in_array($code, ['AccessDeniedException', 'UnauthorizedException', 'InvalidGrantTokenException'])) {
            throw new KmsAccessDeniedException('aws_kms', $e->getAwsErrorMessage(), $e);
        }

        throw new KmsUnavailableException('aws_kms', $e->getAwsErrorMessage(), $e);
    }
}
