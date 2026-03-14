<?php

namespace App\Infrastructure\Git\Exceptions;

use RuntimeException;

class GitFileNotFoundException extends RuntimeException
{
    public function __construct(string $path, string $ref = 'HEAD')
    {
        parent::__construct("File not found: {$path} at ref {$ref}");
    }
}
