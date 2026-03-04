<?php

namespace App\Domain\Shared\Enums;

enum KmsProvider: string
{
    case AwsKms = 'aws_kms';
    case GcpKms = 'gcp_kms';
    case AzureKeyVault = 'azure_key_vault';

    public function label(): string
    {
        return match ($this) {
            self::AwsKms => 'AWS KMS',
            self::GcpKms => 'GCP Cloud KMS',
            self::AzureKeyVault => 'Azure Key Vault',
        };
    }
}
