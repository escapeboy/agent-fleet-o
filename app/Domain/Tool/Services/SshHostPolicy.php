<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Exceptions\SshHostNotAllowedException;

interface SshHostPolicy
{
    /**
     * Validate that the SSH host is allowed.
     *
     * @throws SshHostNotAllowedException
     */
    public function validateHost(string $host): void;
}
