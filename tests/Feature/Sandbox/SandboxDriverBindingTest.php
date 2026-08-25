<?php

namespace Tests\Feature\Sandbox;

use App\Domain\Agent\Contracts\SandboxDriverInterface;
use App\Domain\Agent\Services\AgentSandbox;
use App\Domain\Agent\Services\Sandbox\DockerSandboxDriver;
use App\Domain\Agent\Services\Sandbox\ModalSandboxDriver;
use Tests\TestCase;

class SandboxDriverBindingTest extends TestCase
{
    public function test_default_driver_is_docker(): void
    {
        config(['sandbox.driver' => 'docker']);

        $driver = app(SandboxDriverInterface::class);

        $this->assertInstanceOf(DockerSandboxDriver::class, $driver);
        $this->assertSame('docker', $driver->name());
        $this->assertSame('docker', app(AgentSandbox::class)->driverName());
    }

    public function test_modal_driver_resolves_when_selected(): void
    {
        config([
            'sandbox.driver' => 'modal',
            'sandbox.drivers.modal.endpoint_url' => 'https://x.modal.run',
            'sandbox.drivers.modal.endpoint_token' => 't',
        ]);
        app()->forgetInstance(SandboxDriverInterface::class);

        $driver = app(SandboxDriverInterface::class);

        $this->assertInstanceOf(ModalSandboxDriver::class, $driver);
        $this->assertSame('modal', $driver->name());
    }

    public function test_unknown_driver_throws(): void
    {
        config(['sandbox.driver' => 'bogus']);
        app()->forgetInstance(SandboxDriverInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown sandbox driver [bogus]');

        app(SandboxDriverInterface::class);
    }
}
