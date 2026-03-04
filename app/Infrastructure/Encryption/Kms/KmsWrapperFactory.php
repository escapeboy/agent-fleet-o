<?php

namespace App\Infrastructure\Encryption\Kms;

use App\Domain\Shared\Enums\KmsProvider;
use App\Infrastructure\Encryption\Kms\Contracts\KmsWrapperInterface;
use App\Infrastructure\Encryption\Kms\Providers\AwsKmsWrapper;
use App\Infrastructure\Encryption\Kms\Providers\AzureKeyVaultWrapper;
use App\Infrastructure\Encryption\Kms\Providers\GcpKmsWrapper;

class KmsWrapperFactory
{
    public function make(KmsProvider $provider): KmsWrapperInterface
    {
        return match ($provider) {
            KmsProvider::AwsKms => new AwsKmsWrapper,
            KmsProvider::GcpKms => new GcpKmsWrapper,
            KmsProvider::AzureKeyVault => new AzureKeyVaultWrapper,
        };
    }
}
