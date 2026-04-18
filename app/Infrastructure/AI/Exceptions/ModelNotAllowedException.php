<?php

namespace App\Infrastructure\AI\Exceptions;

class ModelNotAllowedException extends \RuntimeException
{
    public function __construct(string $provider, string $model, string $teamId)
    {
        parent::__construct("Model {$provider}/{$model} is not allowed for team {$teamId}.");
    }
}
