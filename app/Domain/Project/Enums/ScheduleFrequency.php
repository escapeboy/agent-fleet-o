<?php

namespace App\Domain\Project\Enums;

enum ScheduleFrequency: string
{
    case Once = 'once';
    case Every5Minutes = 'every_5_minutes';
    case Every10Minutes = 'every_10_minutes';
    case Every15Minutes = 'every_15_minutes';
    case Every30Minutes = 'every_30_minutes';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Cron = 'cron';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Once',
            self::Every5Minutes => 'Every 5 Minutes',
            self::Every10Minutes => 'Every 10 Minutes',
            self::Every15Minutes => 'Every 15 Minutes',
            self::Every30Minutes => 'Every 30 Minutes',
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
            self::Every5Minutes => '*/5 * * * *',
            self::Every10Minutes => '*/10 * * * *',
            self::Every15Minutes => '*/15 * * * *',
            self::Every30Minutes => '*/30 * * * *',
            self::Hourly => '0 * * * *',
            self::Daily => '0 9 * * *',
            self::Weekly => '0 9 * * 1',
            self::Monthly => '0 9 1 * *',
            default => null,
        };
    }
}
