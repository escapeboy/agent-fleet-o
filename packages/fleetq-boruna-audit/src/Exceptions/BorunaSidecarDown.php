<?php

namespace FleetQ\BorunaAudit\Exceptions;

use RuntimeException;

class BorunaSidecarDown extends RuntimeException
{
    public function __construct(string $reason = 'Boruna binary is unreachable or timed out.')
    {
        parent::__construct($reason);
    }
}
