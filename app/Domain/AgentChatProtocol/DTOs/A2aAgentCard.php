<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\DTOs;

use App\Domain\AgentChatProtocol\Exceptions\A2aDiscoveryException;

/**
 * Immutable representation of an A2A AgentCard (the JSON manifest an A2A agent
 * publishes at `/.well-known/agent-card.json`).
 *
 * Normalizes the two card shapes seen in the wild:
 *   - newer spec: `supportedInterfaces[].url`, `securitySchemes`
 *   - older spec: top-level `url`, `authentication.schemes`
 *
 * @see https://a2a-protocol.org/latest/specification/ §4.4.1 AgentCard
 *
 * @phpstan-type SkillShape array{id?: string, name?: string, description?: string, inputModes?: list<string>, outputModes?: list<string>, examples?: list<string>}
 */
final readonly class A2aAgentCard
{
    /**
     * @param  array<string, mixed>  $raw  the verbatim card document
     * @param  list<string>  $securitySchemes  declared auth scheme names (e.g. ["Bearer", "OAuth2"])
     * @param  list<array<string, mixed>>  $skills
     * @param  array<string, mixed>  $capabilities  the card's raw `capabilities` object
     * @param  array<string, mixed>|null  $provider
     * @param  list<string>  $defaultInputModes
     * @param  list<string>  $defaultOutputModes
     */
    private function __construct(
        public string $name,
        public string $description,
        public string $endpointUrl,
        public ?string $version,
        public array $capabilities,
        public array $securitySchemes,
        public array $skills,
        public ?array $provider,
        public array $defaultInputModes,
        public array $defaultOutputModes,
        public array $raw,
    ) {}

    /**
     * Parse and validate a decoded AgentCard JSON document.
     *
     * @param  array<string, mixed>  $card
     *
     * @throws A2aDiscoveryException when required fields are missing or malformed
     */
    public static function fromArray(array $card): self
    {
        $name = self::nonEmptyString($card['name'] ?? null);
        if ($name === null) {
            throw new A2aDiscoveryException('A2A AgentCard is missing the required "name" field.');
        }

        $description = self::nonEmptyString($card['description'] ?? null);
        if ($description === null) {
            throw new A2aDiscoveryException('A2A AgentCard is missing the required "description" field.');
        }

        $endpointUrl = self::resolveEndpoint($card);
        if ($endpointUrl === null) {
            throw new A2aDiscoveryException('A2A AgentCard has no service endpoint (expected "supportedInterfaces[].url" or top-level "url").');
        }

        return new self(
            name: $name,
            description: $description,
            endpointUrl: $endpointUrl,
            version: self::nonEmptyString($card['version'] ?? null),
            capabilities: is_array($card['capabilities'] ?? null) ? $card['capabilities'] : [],
            securitySchemes: self::resolveSecuritySchemes($card),
            skills: self::resolveSkills($card),
            provider: is_array($card['provider'] ?? null) ? $card['provider'] : null,
            defaultInputModes: self::stringList($card['defaultInputModes'] ?? null),
            defaultOutputModes: self::stringList($card['defaultOutputModes'] ?? null),
            raw: $card,
        );
    }

    /**
     * Normalized capability bag stored on ExternalAgent.capabilities.
     *
     * @return array<string, mixed>
     */
    public function toCapabilities(): array
    {
        return [
            'source' => 'a2a',
            'agent_version' => $this->version,
            'streaming' => (bool) ($this->capabilities['streaming'] ?? false),
            'push_notifications' => (bool) ($this->capabilities['pushNotifications'] ?? false),
            'state_transition_history' => (bool) ($this->capabilities['stateTransitionHistory'] ?? false),
            'extended_card' => (bool) ($this->capabilities['extendedAgentCard'] ?? false),
            'security_schemes' => $this->securitySchemes,
            'input_modes' => $this->defaultInputModes,
            'output_modes' => $this->defaultOutputModes,
            'skill_count' => count($this->skills),
        ];
    }

    /**
     * Metadata stored on ExternalAgent.metadata.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'a2a' => [
                'skills' => $this->skills,
                'provider' => $this->provider,
            ],
        ];
    }

    /**
     * Resolve the preferred service endpoint, preferring the first declared
     * interface (newer spec), falling back to the top-level `url` (older spec).
     *
     * @param  array<string, mixed>  $card
     */
    private static function resolveEndpoint(array $card): ?string
    {
        $interfaces = $card['supportedInterfaces'] ?? null;
        if (is_array($interfaces)) {
            foreach ($interfaces as $interface) {
                if (is_array($interface)) {
                    $url = self::nonEmptyString($interface['url'] ?? null);
                    if ($url !== null) {
                        return $url;
                    }
                }
            }
        }

        return self::nonEmptyString($card['url'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    private static function resolveSecuritySchemes(array $card): array
    {
        // Newer spec: securitySchemes is a map of name => scheme definition.
        $schemes = $card['securitySchemes'] ?? null;
        if (is_array($schemes) && $schemes !== []) {
            return array_values(array_filter(array_map(
                static fn ($key): ?string => is_string($key) ? $key : null,
                array_keys($schemes),
            )));
        }

        // Older spec: authentication.schemes is a list of scheme names.
        $auth = $card['authentication'] ?? null;
        if (is_array($auth)) {
            return self::stringList($auth['schemes'] ?? null);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $card
     * @return list<array<string, mixed>>
     */
    private static function resolveSkills(array $card): array
    {
        $skills = $card['skills'] ?? null;
        if (! is_array($skills)) {
            return [];
        }

        return array_values(array_filter(
            $skills,
            static fn ($skill): bool => is_array($skill),
        ));
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): ?string => is_string($item) ? $item : null,
            $value,
        )));
    }
}
