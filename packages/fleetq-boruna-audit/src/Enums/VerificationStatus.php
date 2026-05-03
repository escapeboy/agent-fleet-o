<?php

namespace FleetQ\BorunaAudit\Enums;

enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
}
