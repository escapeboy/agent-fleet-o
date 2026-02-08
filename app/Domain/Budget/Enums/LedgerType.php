<?php

namespace App\Domain\Budget\Enums;

enum LedgerType: string
{
    case Purchase = 'purchase';
    case Deduction = 'deduction';
    case Refund = 'refund';
    case Reservation = 'reservation';
    case Release = 'release';
}
