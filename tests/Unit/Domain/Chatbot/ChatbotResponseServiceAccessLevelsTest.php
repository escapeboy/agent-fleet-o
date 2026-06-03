<?php

namespace Tests\Unit\Domain\Chatbot;

use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ChatbotResponseServiceAccessLevelsTest extends TestCase
{
    private ReflectionMethod $method;

    private ChatbotResponseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // allowedAccessLevels() only reads $chatbot->type; build the service
        // without running the constructor so no AI/embedding deps are needed.
        $this->service = (new ReflectionClass(ChatbotResponseService::class))
            ->newInstanceWithoutConstructor();

        $this->method = new ReflectionMethod(ChatbotResponseService::class, 'allowedAccessLevels');
        $this->method->setAccessible(true);
    }

    private function levelsFor(ChatbotType $type): array
    {
        $chatbot = new Chatbot;
        $chatbot->type = $type;

        return $this->method->invoke($this->service, $chatbot);
    }

    public function test_help_bot_grants_public_and_key_only(): void
    {
        $this->assertSame(['public', 'key'], $this->levelsFor(ChatbotType::HelpBot));
    }

    public function test_developer_assistant_grants_internal_and_code(): void
    {
        $this->assertSame(['internal', 'code'], $this->levelsFor(ChatbotType::DeveloperAssistant));
    }

    public function test_support_assistant_grants_representative_tier_without_code(): void
    {
        $levels = $this->levelsFor(ChatbotType::SupportAssistant);

        $this->assertSame(['public', 'key', 'representative', 'internal'], $levels);
        $this->assertNotContains('code', $levels);
    }

    public function test_custom_chatbot_is_least_privilege(): void
    {
        $levels = $this->levelsFor(ChatbotType::Custom);

        $this->assertSame(['public', 'key'], $levels);
        $this->assertNotContains('code', $levels);
        $this->assertNotContains('internal', $levels);
        $this->assertNotContains('representative', $levels);
    }

    public function test_no_chatbot_type_grants_code_except_developer_assistant(): void
    {
        foreach (ChatbotType::cases() as $type) {
            $levels = $this->levelsFor($type);

            if ($type === ChatbotType::DeveloperAssistant) {
                $this->assertContains('code', $levels);

                continue;
            }

            $this->assertNotContains('code', $levels, "{$type->value} must not be granted 'code' access");
        }
    }
}
