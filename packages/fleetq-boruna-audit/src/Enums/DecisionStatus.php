<?php

namespace FleetQ\BorunaAudit\Enums;

enum DecisionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Tampered = 'tampered';
}
