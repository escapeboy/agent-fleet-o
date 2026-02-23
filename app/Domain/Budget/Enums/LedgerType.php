<?php

namespace App\Domain\Budget\Enums;

enum LedgerType: string
{
    case Purchase = 'purchase';
    case Deduction = 'deduction';
    case Refund = 'refund';
    case Reservation = 'reservation';
    case Release = 'release';
    case MarketplacePurchase = 'marketplace_purchase';
    case MarketplaceRevenue = 'marketplace_revenue';
    case PlatformFee = 'platform_fee';
}
