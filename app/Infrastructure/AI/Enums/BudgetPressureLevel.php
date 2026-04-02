<?php

namespace App\Infrastructure\AI\Enums;

enum BudgetPressureLevel: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
