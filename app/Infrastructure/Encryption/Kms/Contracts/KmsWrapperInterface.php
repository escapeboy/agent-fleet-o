<?php

namespace App\Infrastructure\Encryption\Kms\Contracts;

use App\Infrastructure\Encryption\Kms\Exceptions\KmsAccessDeniedException;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;

interface KmsWrapperInterface
{
    /**
     * Wrap (encrypt) a plaintext DEK using the customer's KMS key.
     *
     * @param  string  $plaintextDek  Raw binary DEK (32 bytes)
     * @param  array  $config  Provider-specific configuration
     * @return string Base64-encoded wrapped DEK
     */
    public function wrap(string $plaintextDek, array $config): string;

    /**
     * Unwrap (decrypt) a KMS-wrapped DEK back to plaintext.
     *
     * @param  string  $wrappedDek  Base64-encoded wrapped DEK
     * @param  array  $config  Provider-specific configuration
     * @return string Raw binary DEK (32 bytes)
     */
    public function unwrap(string $wrappedDek, array $config): string;

    /**
     * Test connectivity and permissions by performing a round-trip wrap/unwrap.
     *
     * @param  array  $config  Provider-specific configuration
     * @return bool True if test succeeds
     *
     * @throws KmsAccessDeniedException
     * @throws KmsUnavailableException
     */
    public function test(array $config): bool;

    /**
     * Return the provider name for logging.
     */
    public function providerName(): string;
}
