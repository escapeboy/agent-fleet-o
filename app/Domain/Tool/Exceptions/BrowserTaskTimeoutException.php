<?php

namespace App\Domain\Tool\Exceptions;

class BrowserTaskTimeoutException extends \RuntimeException
{
    public function __construct(int $timeoutSeconds)
    {
        parent::__construct("Browser task timed out after {$timeoutSeconds}s");
    }
}
