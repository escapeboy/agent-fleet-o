<?php

namespace FleetQ\BorunaAudit\Exceptions;

use RuntimeException;

class BorunaQuotaExceeded extends RuntimeException
{
    public function __construct(string $tenantId, int $used, int $limit)
    {
        parent::__construct(
            "Boruna audit quota exceeded for tenant {$tenantId}: {$used}/{$limit} runs used this period.",
        );
    }
}
