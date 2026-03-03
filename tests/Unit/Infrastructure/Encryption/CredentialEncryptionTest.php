<?php

namespace Tests\Unit\Infrastructure\Encryption;

use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private CredentialEncryption $encryption;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Encryption Test Team',
            'slug' => 'encryption-test',
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $this->team->refresh();

        $this->encryption = app(CredentialEncryption::class);
    }

    public function test_team_gets_credential_key_on_creation(): void
    {
        // Re-read from DB to verify the creating hook persisted the key
        $fromDb = Team::withoutGlobalScopes()->find($this->team->id);
        $this->assertNotNull($fromDb->credential_key);
        $this->assertNotEmpty($fromDb->credential_key);

        // Key should be valid base64 encoding of 32 bytes
        $decoded = base64_decode($this->team->credential_key);
        $this->assertEquals(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($decoded));
    }

    public function test_encrypt_decrypt_with_team_key(): void
    {
        $data = ['api_key' => 'sk-test-12345', 'secret' => 'very-secret'];

        $encrypted = $this->encryption->encrypt($data, $this->team->id);

        $this->assertNotEquals(json_encode($data), $encrypted);

        $decrypted = $this->encryption->decrypt($encrypted, $this->team->id);

        $this->assertEquals($data, $decrypted);
    }

    public function test_v2_encrypted_data_has_correct_envelope_format(): void
    {
        $data = ['key' => 'value'];

        $encrypted = $this->encryption->encrypt($data, $this->team->id);

        $decoded = base64_decode($encrypted, true);
        $this->assertNotFalse($decoded);

        $envelope = json_decode($decoded, true);
        $this->assertIsArray($envelope);
        $this->assertEquals(2, $envelope['v']);
        $this->assertArrayHasKey('n', $envelope);
        $this->assertArrayHasKey('c', $envelope);
    }

    public function test_different_teams_produce_different_ciphertexts(): void
    {
        $owner = User::factory()->create();
        $team2 = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $team2->refresh();

        $data = ['api_key' => 'shared-secret'];

        $enc1 = $this->encryption->encrypt($data, $this->team->id);
        $enc2 = $this->encryption->encrypt($data, $team2->id);

        $this->assertNotEquals($enc1, $enc2);
    }

    public function test_team_cannot_decrypt_other_teams_data(): void
    {
        $owner = User::factory()->create();
        $team2 = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team-2',
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $team2->refresh();

        $data = ['api_key' => 'team1-only'];
        $encrypted = $this->encryption->encrypt($data, $this->team->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credential decryption failed');

        $this->encryption->decrypt($encrypted, $team2->id);
    }

    public function test_v1_legacy_data_can_still_be_decrypted(): void
    {
        $data = ['api_key' => 'legacy-key-123'];
        $json = json_encode($data);

        // Simulate v1 format: encrypted with APP_KEY via Laravel encrypter
        $v1Encrypted = app('encrypter')->encrypt($json, false);

        $decrypted = $this->encryption->decrypt($v1Encrypted, $this->team->id);

        $this->assertEquals($data, $decrypted);
    }

    public function test_fallback_to_app_key_when_no_team_id(): void
    {
        $data = ['key' => 'no-team-data'];

        $encrypted = $this->encryption->encrypt($data, null);
        $decrypted = $this->encryption->decrypt($encrypted, null);

        $this->assertEquals($data, $decrypted);
    }

    public function test_credential_model_stores_and_retrieves_encrypted_data(): void
    {
        $secretData = ['api_key' => 'sk-real-key', 'extra' => 'metadata'];

        $credential = Credential::create([
            'team_id' => $this->team->id,
            'name' => 'Test API Key',
            'slug' => 'test-api-key',
            'credential_type' => 'api_token',
            'status' => 'active',
            'secret_data' => $secretData,
            'metadata' => [],
        ]);

        // Raw DB value should NOT contain plaintext
        $rawValue = $credential->getRawOriginal('secret_data');
        $this->assertStringNotContainsString('sk-real-key', $rawValue);

        // Eloquent cast should decrypt it
        $credential->refresh();
        $this->assertEquals($secretData, $credential->secret_data);
    }

    public function test_credential_model_secret_data_is_hidden_from_serialization(): void
    {
        $credential = Credential::create([
            'team_id' => $this->team->id,
            'name' => 'Hidden Key',
            'slug' => 'hidden-key',
            'credential_type' => 'api_token',
            'status' => 'active',
            'secret_data' => ['api_key' => 'should-not-appear'],
            'metadata' => [],
        ]);

        $array = $credential->toArray();
        $this->assertArrayNotHasKey('secret_data', $array);
    }

    public function test_generate_key_returns_valid_key(): void
    {
        $key = CredentialEncryption::generateKey();

        $decoded = base64_decode($key);
        $this->assertEquals(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($decoded));
    }

    public function test_clear_key_cache_wipes_memory(): void
    {
        // Encrypt to populate key cache
        $this->encryption->encrypt(['data' => 'test'], $this->team->id);

        // Should not throw
        $this->encryption->clearKeyCache();

        // Should still work (re-fetches key from DB)
        $encrypted = $this->encryption->encrypt(['data' => 'test2'], $this->team->id);
        $decrypted = $this->encryption->decrypt($encrypted, $this->team->id);

        $this->assertEquals(['data' => 'test2'], $decrypted);
    }
}
