<?php

declare(strict_types=1);

namespace App\Domain\Outbound\Exceptions;

use RuntimeException;

/**
 * Thrown when the content quality gate is in `block` mode and a proposal
 * fails brand-voice or quality checks. Aborts outbound delivery.
 */
class ContentQualityException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(string $message, public readonly array $reasons = [])
    {
        parent::__construct($message);
    }
}
