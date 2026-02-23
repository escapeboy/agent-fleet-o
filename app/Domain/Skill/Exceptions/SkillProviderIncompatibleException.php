<?php

namespace App\Domain\Skill\Exceptions;

use RuntimeException;

class SkillProviderIncompatibleException extends RuntimeException
{
    public function __construct(
        public readonly array $requiredProviders,
        public readonly array $availableProviders,
        string $skillName = '',
    ) {
        $required = implode(', ', $requiredProviders);
        $available = implode(', ', $availableProviders) ?: 'none';
        parent::__construct(
            "Skill \"{$skillName}\" requires provider(s): {$required}. Your team has: {$available}.",
        );
    }
}
