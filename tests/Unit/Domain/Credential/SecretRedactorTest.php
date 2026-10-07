<?php

namespace Tests\Unit\Domain\Credential;

use App\Domain\Credential\Services\SecretPatternLibrary;
use App\Domain\Credential\Services\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new SecretRedactor(new SecretPatternLibrary);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function positives(): array
    {
        $b64 = 'base64:'.str_repeat('A1b2', 10).'xyz'.'=';
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA1234\nabcdEFGH5678\n-----END RSA PRIVATE KEY-----";
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r';

        return [
            'env password' => ["DB_PASSWORD=s3cr3tPass!\nDB_HOST=db", "DB_PASSWORD=[REDACTED]\nDB_HOST=db"],
            'env app key' => ['APP_KEY='.$b64, 'APP_KEY=[REDACTED]'],
            'env stripe' => ['STRIPE_SECRET=sk_live_'.str_repeat('a1B2', 8), 'STRIPE_SECRET=[REDACTED]'],
            'env export aws' => ['export AWS_SECRET_ACCESS_KEY='.str_repeat('aB3/', 10), 'export AWS_SECRET_ACCESS_KEY=[REDACTED]'],
            'env quoted' => ['MAIL_PASSWORD="hunter2hunter"', 'MAIL_PASSWORD="[REDACTED]"'],
            'credentialed uri' => ['postgres://app:hunter2hunter@db:5432/x', 'postgres://app:[REDACTED]@db:5432/x'],
            'dsn password' => ['Server=x;Database=y;User Id=u;Password=Pa55word99;', 'Server=x;Database=y;User Id=u;Password=[REDACTED];'],
            'json api key' => ['{"api_key": "abcd1234efgh5678"}', '{"api_key": "[REDACTED]"}'],
            'json access token' => ['{"user":"bob","access_token":"tok_abcdef123456"}', '{"user":"bob","access_token":"[REDACTED]"}'],
            'bearer header' => ['Authorization: Bearer abc123def456ghi789jkl', 'Authorization: Bearer [REDACTED]'],
            'api-key header' => ['api-key: abcdef123456', 'api-key: [REDACTED]'],
            'raw jwt' => ["token was {$jwt} ok", 'token was [REDACTED] ok'],
            'aws secret key' => ['aws_secret_access_key = '.str_repeat('aB3/', 10), 'aws_secret_access_key = [REDACTED]'],
            'gitlab pat' => ['glpat-'.str_repeat('a1', 12), '[REDACTED]'],
            'pem block' => ["key:\n{$pem}\nend", "key:\n[REDACTED]\nend"],
        ];
    }

    #[DataProvider('positives')]
    public function test_redacts_and_keeps_surrounding_text(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->redactor->redactString($input)->text);
    }

    #[DataProvider('positives')]
    public function test_is_idempotent(string $input, string $expected): void
    {
        $once = $this->redactor->redactString($input)->text;

        $this->assertSame($expected, $once);
        $this->assertSame($once, $this->redactor->redactString($once)->text);
    }

    public function test_every_vendor_pattern_has_a_redacted_fixture(): void
    {
        $fixtures = [
            'OPENAI_KEY' => 'sk-'.str_repeat('aB1', 14),
            'ANTHROPIC_KEY' => 'sk-ant-'.str_repeat('aB1-', 25),
            'GITHUB_PAT' => 'ghp_'.str_repeat('aB1', 13),
            'GITHUB_OAUTH' => 'gho_'.str_repeat('aB1', 12),
            'GOOGLE_API' => 'AIza'.str_repeat('aB1_', 9).'abc',
            'SLACK_BOT' => 'xoxb-1234567890-1234567890-'.str_repeat('aB1', 8),
            'SLACK_USER' => 'xoxp-1234567890-1234567890-1234567890-'.str_repeat('aB1c', 8),
            'AWS_ACCESS_KEY' => 'AKIA'.str_repeat('AB12', 4),
            'STRIPE_SECRET' => 'sk_live_'.str_repeat('aB1', 10),
            'STRIPE_TEST' => 'sk_test_'.str_repeat('aB1', 10),
            'TELEGRAM_BOT' => '123456789:'.str_repeat('aB1', 12).'x',
            'SENDGRID_KEY' => 'SG.'.str_repeat('aB1', 7).'a.'.str_repeat('aB1', 14).'a',
            'TWILIO_KEY' => 'SK'.str_repeat('0a1b', 8),
            'HUGGINGFACE_TOKEN' => 'hf_'.str_repeat('aB1', 11),
            'SHOPIFY_TOKEN' => 'shpat_'.str_repeat('aB1c', 8),
            'GENERIC_PRIVATE_KEY' => '-----BEGIN PRIVATE KEY-----',
            'JWT' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r',
            'LARAVEL_APP_KEY' => 'base64:'.str_repeat('aB1c', 10).'xyz=',
            'AWS_SECRET_KEY' => 'aws_secret_access_key='.str_repeat('aB3/', 10),
            'PRIVATE_KEY_BLOCK' => "-----BEGIN EC PRIVATE KEY-----\nabc\n-----END EC PRIVATE KEY-----",
            'GITLAB_PAT' => 'glpat-'.str_repeat('a1', 12),
        ];

        $this->assertEqualsCanonicalizing(array_keys((new SecretPatternLibrary)->patterns()), array_keys($fixtures));

        foreach ($fixtures as $id => $secret) {
            $result = $this->redactor->redactString("before {$secret} after");

            $this->assertGreaterThan(0, $result->total(), "{$id} was not redacted");
            $this->assertStringContainsString('[REDACTED]', $result->text, $id);
            $this->assertStringContainsString('before ', $result->text, $id);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function negatives(): array
    {
        return [
            'env var reference' => ['DB_PASSWORD=${DB_PASSWORD}'],
            'empty value' => ['DB_PASSWORD='],
            'your- placeholder' => ['API_KEY=your-api-key'],
            'changeme' => ['TOKEN=changeme'],
            'angle placeholder' => ['SECRET=<secret>'],
            'mask' => ['PASSWORD=****'],
            'null' => ['KEY=null'],
            'python environ' => ['API_KEY = os.environ["API_KEY"]'],
            'php env()' => ["\$key = env('STRIPE_SECRET');"],
            'js process.env' => ['const token = process.env.TOKEN'],
            'laravel config' => ["'secret' => config('services.stripe.secret'),"],
            'git sha' => ['commit 9fceb02d0ae598e95dc970b74767f19372d61af8'],
            'uuid' => ['id 0192f4a8-7c1e-7d3b-9f2a-5b6c7d8e9f01'],
            'sha256' => ['sha256 e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
            'base64 fragment' => ['data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='],
            'prose' => ['set your password in the settings page'],
            'prose bearer' => ['use bearer authentication for the API'],
            'author env' => ['AUTHOR=Nikola Katsarov'],
            'max tokens' => ['MAX_TOKENS=100000'],
            'pwd path' => ['PWD=/home/user/project'],
            'json placeholder' => ['{"password": "your-password"}'],
            'json short' => ['{"token": "abc"}'],
            'uri without password' => ['https://user@example.com/path'],
            'template' => ['DB_PASSWORD={{ secrets.db }}'],
        ];
    }

    #[DataProvider('negatives')]
    public function test_does_not_over_redact(string $input): void
    {
        $result = $this->redactor->redactString($input);

        $this->assertSame($input, $result->text);
        $this->assertSame(0, $result->total());
    }

    public function test_counts_reflect_layers_and_total_matches_replacements(): void
    {
        $result = $this->redactor->redactString("DB_PASSWORD=s3cr3tPass!\nREDIS_URL=redis://u:hunter2hunter@r:6379\n{\"api_key\": \"abcd1234efgh5678\"}");

        $this->assertSame(['credentialed_uri' => 1, 'env_assignment' => 1, 'json_secret' => 1], $result->counts);
        $this->assertSame(3, $result->total());
    }

    public function test_walks_nested_arrays_leaving_keys_and_non_strings(): void
    {
        $input = ['steps' => [['out' => 'DB_PASSWORD=abcdefgh', 'n' => 5, 'ok' => true, 'none' => null]]];

        $this->assertSame(
            ['steps' => [['out' => 'DB_PASSWORD=[REDACTED]', 'n' => 5, 'ok' => true, 'none' => null]]],
            $this->redactor->redact($input),
        );
    }

    public function test_unterminated_pem_header_in_large_text_does_not_exhaust_backtracking(): void
    {
        $text = "-----BEGIN PRIVATE KEY-----\n".str_repeat("const header = 'value';\n", 60000);

        $this->assertGreaterThan(1_000_000, strlen($text));
        // The header and the key-like run after it are masked; the rest of the text survives.
        $out = $this->redactor->redactString($text)->text;
        $this->assertStringStartsWith('[REDACTED]', $out);
        $this->assertStringEndsWith(str_repeat("const header = 'value';\n", 59999), $out);
    }

    public function test_json_slash_escapes_and_legacy_headers_do_not_cut_a_truncated_key_short(): void
    {
        $tail = 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZg';
        $jsonEscaped = '"-----BEGIN RSA PRIVATE KEY-----\nMIIEow\/IBAAKCAQEA\/xyz0123\/'.$tail;
        $legacy = "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,0A1B2C3D\n\n{$tail}";

        $this->assertStringNotContainsString($tail, $this->redactor->redactString($jsonEscaped)->text);
        $this->assertStringNotContainsString($tail, $this->redactor->redactString($legacy)->text);
    }

    public function test_pem_block_is_not_reported_twice_by_the_library(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA1234\n-----END RSA PRIVATE KEY-----";

        $this->assertCount(1, (new SecretPatternLibrary)->scan($pem));
    }

    public function test_json_escaped_and_truncated_pem_bodies_are_redacted(): void
    {
        $body = 'MIIEowIBAAKCAQEAxyz0123456789abcdefABCDEF';
        $escaped = '{"key":"-----BEGIN PRIVATE KEY-----\n'.$body.'\n'.$body.'\n-----END PRIVATE KEY-----\n"}';
        $truncated = "-----BEGIN RSA PRIVATE KEY-----\n{$body}\n{$body}\n";

        $this->assertStringNotContainsString($body, $this->redactor->redactString($escaped)->text);
        $this->assertStringNotContainsString($body, $this->redactor->redactString($truncated)->text);
    }

    public function test_terminated_key_with_large_body_is_redacted(): void
    {
        $line = str_repeat('A', 64);
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n".str_repeat($line."\n", 200).'-----END RSA PRIVATE KEY-----';

        $this->assertSame('[REDACTED]', $this->redactor->redactString($pem)->text);
    }

    public function test_library_detects_key_types_the_header_pattern_misses(): void
    {
        $library = new SecretPatternLibrary;
        $encrypted = "-----BEGIN ENCRYPTED PRIVATE KEY-----\nMIIFHDBOBgkqhkiG9w0BBQ0wQTApBgkq\n-----END ENCRYPTED PRIVATE KEY-----";
        $dsa = "-----BEGIN DSA PRIVATE KEY-----\nMIIBuwIBAAKBgQDhqK5uG8TwKx01234\n-----END DSA PRIVATE KEY-----";

        $this->assertSame(['PRIVATE_KEY_BLOCK'], array_column($library->scan($encrypted), 'pattern_id'));
        $this->assertSame(['PRIVATE_KEY_BLOCK'], array_column($library->scan($dsa), 'pattern_id'));
    }

    public function test_non_string_scalars_and_null_pass_through(): void
    {
        foreach ([null, 5, 1.5, true, false] as $value) {
            $this->assertSame($value, $this->redactor->redact($value));
        }
    }
}
