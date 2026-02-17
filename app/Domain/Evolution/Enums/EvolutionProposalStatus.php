<?php

namespace App\Domain\Evolution\Enums;

enum EvolutionProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Applied = 'applied';
    case Rejected = 'rejected';
}
