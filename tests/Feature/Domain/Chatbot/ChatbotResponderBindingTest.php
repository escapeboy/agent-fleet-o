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
        // Barsy\Services\EmbeddingServiceInterface is provided by the downstream
        // Barsy layer and does not exist in this repo — stub it so the concrete
        // ChatbotResponseService can be constructed.
        $this->app->instance(
            'Barsy\Services\EmbeddingServiceInterface',
            Mockery::mock('Barsy\Services\EmbeddingServiceInterface'),
        );

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
