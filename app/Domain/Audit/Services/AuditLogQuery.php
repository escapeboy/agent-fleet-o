<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the filtered audit-log query shared by the /audit page and its export.
 * Team isolation comes from AuditEntry's TeamScope.
 */
class AuditLogQuery
{
    /**
     * @return Builder<AuditEntry>
     */
    public static function build(string $search = '', string $eventFilter = '', ?string $from = null, ?string $to = null): Builder
    {
        $query = AuditEntry::with('user')->latest('created_at');

        if ($search !== '') {
            $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function ($q) use ($search, $op) {
                $q->where('event', $op, "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', $op, "%{$search}%"));
            });
        }

        if ($eventFilter !== '') {
            $query->where('event', 'like', "{$eventFilter}.%");
        }

        $fromDate = self::parseDate($from);
        if ($fromDate !== null) {
            $query->where('created_at', '>=', $fromDate->startOfDay());
        }

        $toDate = self::parseDate($to);
        if ($toDate !== null) {
            $query->where('created_at', '<=', $toDate->endOfDay());
        }

        return $query;
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
