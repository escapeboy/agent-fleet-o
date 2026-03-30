<?php

namespace App\Domain\Skill\Exceptions;

use RuntimeException;

/**
 * Thrown when GenerateImprovedSkillVersionAction is called
 * without sufficient annotations (requires at least 1 good and 1 bad).
 */
class InsufficientAnnotationsException extends RuntimeException
{
    public function __construct(string $message = 'Insufficient annotations: at least 1 good and 1 bad annotation are required.')
    {
        parent::__construct($message);
    }
}
