<?php

namespace FleetQ\BorunaAudit\Contracts;

interface AuditableSubject
{
    public function subjectType(): string;

    public function subjectId(): string;
}
