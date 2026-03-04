<?php

namespace Tests\Unit\Infrastructure\Encryption;

use App\Domain\Shared\Actions\ConfigureTeamKmsAction;
use App\Domain\Shared\Actions\RemoveTeamKmsAction;
use App\Domain\Shared\Actions\RewrapTeamDekAction;
use App\Domain\Shared\Actions\TestKmsConnectivityAction;
use App\Domain\Shared\Enums\KmsConfigStatus;
use App\Domain\Shared\Enums\KmsProvider;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamKmsConfig;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Infrastructure\Encryption\Kms\Contracts\KmsWrapperInterface;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;
use App\Infrastructure\Encryption\Kms\KmsWrapperFactory;
use App\Infrastructure\Encryption\Kms\KmsWrapperService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KmsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private KmsWrapperInterface $mockWrapper;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();

        $this->team = Team::create([
            'name' => 'KMS Test Team',
            'slug' => 'kms-test',
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $this->team->refresh();

        // Create a mock KMS wrapper that uses simple base64 for wrap/unwrap
        $this->mockWrapper = new class implements KmsWrapperInterface
        {
            public bool $shouldFail = false;

            public function wrap(string $plaintextDek, array $config): string
            {
                if ($this->shouldFail) {
                    throw new KmsUnavailableException('mock', 'Mock KMS failure');
                }

                return base64_encode('mock-wrapped:' . $plaintextDek);
            }

            public function unwrap(string $wrappedDek, array $config): string
            {
                if ($this->shouldFail) {
                    throw new KmsUnavailableException('mock', 'Mock KMS failure');
                }

                $decoded = base64_decode($wrappedDek);

                return str_replace('mock-wrapped:', '', $decoded);
            }

            public function test(array $config): bool
            {
                if ($this->shouldFail) {
                    throw new KmsUnavailableException('mock', 'Mock KMS failure');
                }

                return true;
            }

            public function providerName(): string
            {
                return 'mock_kms';
            }
        };

        // Bind the mock factory
        $mockFactory = $this->createMock(KmsWrapperFactory::class);
        $mockFactory->method('make')->willReturn($this->mockWrapper);
        $this->app->instance(KmsWrapperFactory::class, $mockFactory);
    }

    public function test_test_kms_connectivity_succeeds(): void
    {
        $action = app(TestKmsConnectivityAction::class);

        $result = $action->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
        );

        $this->assertTrue($result['success']);
    }

    public function test_test_kms_connectivity_fails_gracefully(): void
    {
        $this->mockWrapper->shouldFail = true;

        $action = app(TestKmsConnectivityAction::class);

        $result = $action->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Mock KMS failure', $result['message']);
    }

    public function test_configure_team_kms_creates_config(): void
    {
        $action = app(ConfigureTeamKmsAction::class);

        $result = $action->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
            'arn:aws:kms:us-east-1:123456:key/test-key',
        );

        $this->assertTrue($result['success']);

        $kmsConfig = TeamKmsConfig::where('team_id', $this->team->id)->first();
        $this->assertNotNull($kmsConfig);
        $this->assertEquals(KmsProvider::AwsKms, $kmsConfig->provider);
        $this->assertEquals(KmsConfigStatus::Active, $kmsConfig->status);
        $this->assertNotEmpty($kmsConfig->wrapped_dek);
    }

    public function test_credential_encryption_uses_kms_when_active(): void
    {
        // First configure KMS
        $action = app(ConfigureTeamKmsAction::class);
        $action->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
            'arn:aws:kms:us-east-1:123456:key/test-key',
        );

        // Clear any cached keys
        $encryption = app(CredentialEncryption::class);
        $encryption->clearKeyCache();

        // Now encrypt/decrypt should work via KMS path
        $data = ['api_key' => 'kms-protected-key'];
        $encrypted = $encryption->encrypt($data, $this->team->id);
        $decrypted = $encryption->decrypt($encrypted, $this->team->id);

        $this->assertEquals($data, $decrypted);
    }

    public function test_kms_error_state_throws_exception(): void
    {
        // Create a KMS config in error state
        TeamKmsConfig::create([
            'team_id' => $this->team->id,
            'provider' => KmsProvider::AwsKms,
            'credentials' => ['role_arn' => 'test'],
            'wrapped_dek' => base64_encode('test'),
            'key_identifier' => 'test-key',
            'status' => KmsConfigStatus::Error,
        ]);

        $encryption = app(CredentialEncryption::class);
        $encryption->clearKeyCache();

        $this->expectException(KmsUnavailableException::class);
        $encryption->encrypt(['test' => 'data'], $this->team->id);
    }

    public function test_remove_kms_deletes_config(): void
    {
        // First configure KMS
        app(ConfigureTeamKmsAction::class)->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
            'arn:aws:kms:us-east-1:123456:key/test-key',
        );

        $this->assertNotNull(TeamKmsConfig::where('team_id', $this->team->id)->first());

        // Remove KMS
        $result = app(RemoveTeamKmsAction::class)->execute($this->team->id);

        $this->assertTrue($result['success']);
        $this->assertNull(TeamKmsConfig::where('team_id', $this->team->id)->first());
    }

    public function test_remove_kms_force_when_unreachable(): void
    {
        // Configure KMS then make wrapper fail
        app(ConfigureTeamKmsAction::class)->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
            'arn:aws:kms:us-east-1:123456:key/test-key',
        );

        $this->mockWrapper->shouldFail = true;

        // Normal remove should fail
        $result = app(RemoveTeamKmsAction::class)->execute($this->team->id);
        $this->assertFalse($result['success']);

        // Force remove should succeed
        $result = app(RemoveTeamKmsAction::class)->execute($this->team->id, force: true);
        $this->assertTrue($result['success']);
        $this->assertNull(TeamKmsConfig::where('team_id', $this->team->id)->first());
    }

    public function test_rewrap_dek_updates_wrapped_dek(): void
    {
        app(ConfigureTeamKmsAction::class)->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
            'arn:aws:kms:us-east-1:123456:key/test-key',
        );

        $config = TeamKmsConfig::where('team_id', $this->team->id)->first();
        $originalWrappedDek = $config->wrapped_dek;
        $originalTimestamp = $config->dek_wrapped_at;

        // Re-wrap
        $result = app(RewrapTeamDekAction::class)->execute($this->team->id);
        $this->assertTrue($result['success']);

        $config->refresh();
        // Wrapped DEK may be the same bytes but dek_wrapped_at should be updated
        $this->assertTrue($config->dek_wrapped_at > $originalTimestamp);
    }

    public function test_data_survives_kms_enable_and_remove_cycle(): void
    {
        $encryption = app(CredentialEncryption::class);

        // Encrypt before KMS
        $data = ['api_key' => 'pre-kms-data'];
        $encryptedBefore = $encryption->encrypt($data, $this->team->id);
        $encryption->clearKeyCache();

        // Enable KMS
        app(ConfigureTeamKmsAction::class)->execute(
            $this->team->id,
            KmsProvider::AwsKms,
            ['role_arn' => 'test', 'key_arn' => 'test', 'region' => 'us-east-1'],
            'arn:aws:kms:us-east-1:123456:key/test-key',
        );
        $encryption->clearKeyCache();

        // Data encrypted before KMS should still decrypt (same DEK, different wrapping)
        $decrypted = $encryption->decrypt($encryptedBefore, $this->team->id);
        $this->assertEquals($data, $decrypted);

        // Encrypt new data with KMS-wrapped DEK
        $newData = ['api_key' => 'kms-era-data'];
        $encryptedAfter = $encryption->encrypt($newData, $this->team->id);
        $encryption->clearKeyCache();

        // Remove KMS
        app(RemoveTeamKmsAction::class)->execute($this->team->id);
        $encryption->clearKeyCache();

        // Both old and new data should still decrypt (DEK unchanged, only wrapping reverts)
        $this->assertEquals($data, $encryption->decrypt($encryptedBefore, $this->team->id));
        $this->assertEquals($newData, $encryption->decrypt($encryptedAfter, $this->team->id));
    }

    public function test_kms_config_model_hides_sensitive_fields(): void
    {
        $config = TeamKmsConfig::create([
            'team_id' => $this->team->id,
            'provider' => KmsProvider::AwsKms,
            'credentials' => ['role_arn' => 'secret-arn', 'key_arn' => 'key'],
            'wrapped_dek' => base64_encode('wrapped-key'),
            'key_identifier' => 'arn:aws:kms:test',
            'status' => KmsConfigStatus::Active,
        ]);

        $array = $config->toArray();

        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertArrayNotHasKey('wrapped_dek', $array);
    }

    public function test_kms_provider_enum_labels(): void
    {
        $this->assertEquals('AWS KMS', KmsProvider::AwsKms->label());
        $this->assertEquals('GCP Cloud KMS', KmsProvider::GcpKms->label());
        $this->assertEquals('Azure Key Vault', KmsProvider::AzureKeyVault->label());
    }
}
