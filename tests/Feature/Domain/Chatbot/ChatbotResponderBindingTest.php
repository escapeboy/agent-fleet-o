<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Chatbot\Contracts\ChatbotResponderInterface;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use Mockery;
use Tests\TestCase;

class ChatbotResponderBindingTest extends TestCase
{
    public function test_container_resolves_responder_interface_to_default_service(): void
    {
        // No stub: the service must resolve from FleetQ's own bindings alone
        // (it used to need Barsy's EmbeddingServiceInterface and failed in prod).
        $resolved = $this->app->make(ChatbotResponderInterface::class);

        $this->assertInstanceOf(ChatbotResponseService::class, $resolved);
    }

    public function test_downstream_layer_can_rebind_responder(): void
    {
        $custom = Mockery::mock(ChatbotResponderInterface::class);
        $this->app->instance(ChatbotResponderInterface::class, $custom);

        $this->assertSame($custom, $this->app->make(ChatbotResponderInterface::class));
    }
}
