<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\DTOs;

final readonly class AgentManifestDTO
{
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public string $protocolUri,
        public array $supportedMessageTypes,
        public string $endpoint,
        public string $authScheme,
        public array $capabilities,
        public array $fleetqExtension,
        public string $version,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'name' => $this->name,
            'description' => $this->description,
            'protocol' => $this->protocolUri,
            'supported_message_types' => $this->supportedMessageTypes,
            'endpoint' => $this->endpoint,
            'auth_scheme' => $this->authScheme,
            'capabilities' => $this->capabilities,
            'fleetq_extension' => $this->fleetqExtension,
            'manifest_version' => $this->version,
        ];
    }
}
