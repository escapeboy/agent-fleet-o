<?php

namespace App\Domain\Tool\Exceptions;

class BrowserTaskFailedException extends \RuntimeException
{
    public function __construct(string $reason = 'Browser task failed')
    {
        parent::__construct($reason);
    }
}
