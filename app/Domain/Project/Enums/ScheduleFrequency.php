<?php

namespace App\Domain\Project\Enums;

enum ScheduleFrequency: string
{
    case Once = 'once';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Cron = 'cron';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Once',
            self::Hourly => 'Every Hour',
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Cron => 'Custom (Cron)',
        };
    }

    public function toCronExpression(): ?string
    {
        return match ($this) {
            self::Hourly => '0 * * * *',
            self::Daily => '0 9 * * *',
            self::Weekly => '0 9 * * 1',
            self::Monthly => '0 9 1 * *',
            default => null,
        };
    }
}
