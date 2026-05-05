<?php

declare(strict_types=1);

namespace Tests\Unit\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Services\HmacJwtVerifier;
use PHPUnit\Framework\TestCase;

class HmacJwtVerifierTest extends TestCase
{
    public function test_sign_and_verify_round_trip(): void
    {
        $verifier = new HmacJwtVerifier;
        $secret = 'super-secret-key';

        $token = $verifier->sign(['sub' => 'agent:42'], $secret, 60);
        $claims = $verifier->verify($token, $secret);

        $this->assertSame('agent:42', $claims['sub']);
        $this->assertArrayHasKey('exp', $claims);
        $this->assertArrayHasKey('iat', $claims);
    }

    public function test_wrong_secret_fails_verification(): void
    {
        $verifier = new HmacJwtVerifier;
        $token = $verifier->sign(['sub' => 'agent:1'], 'secret-a');

        $this->expectException(\InvalidArgumentException::class);
        $verifier->verify($token, 'secret-b');
    }

    public function test_expired_token_fails_verification(): void
    {
        $verifier = new HmacJwtVerifier;
        $token = $verifier->sign(
            ['sub' => 'agent:1', 'exp' => time() - 10],
            'secret',
        );

        $this->expectException(\InvalidArgumentException::class);
        $verifier->verify($token, 'secret');
    }

    public function test_malformed_token_fails(): void
    {
        $verifier = new HmacJwtVerifier;
        $this->expectException(\InvalidArgumentException::class);
        $verifier->verify('not.a.valid.jwt.format', 'secret');
    }
}
