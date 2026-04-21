<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\ClientDisconnectedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ClientDisconnectedExceptionTest extends TestCase
{
    public function test_has_default_message(): void
    {
        $exception = new ClientDisconnectedException;
        $this->assertSame('Client disconnected', $exception->getMessage());
    }

    public function test_accepts_custom_message(): void
    {
        $exception = new ClientDisconnectedException('Socket write failed');
        $this->assertSame('Socket write failed', $exception->getMessage());
    }

    public function test_is_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new ClientDisconnectedException);
    }
}
