<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\AuditLogQuery;
use App\Domain\Audit\Services\OcsfMapper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the current team's audit log as CSV or OCSF JSON Lines.
 * Applies the same filters as the /audit page. Team isolation comes from TeamScope.
 */
class AuditLogExportController extends Controller
{
    private const CSV_HEADER = [
        'id', 'created_at', 'event', 'user_id', 'user_name', 'subject_type', 'subject_id',
        'triggered_by', 'ip_address', 'ocsf_class_uid', 'ocsf_severity_id', 'properties',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless(Gate::allows('manage-team'), 403);

        $validated = $request->validate([
            'format' => ['required', 'in:csv,jsonl'],
            'search' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $format = (string) $validated['format'];
        $filters = [
            'search' => isset($validated['search']) ? (string) $validated['search'] : null,
            'event' => isset($validated['event']) ? (string) $validated['event'] : null,
            'from' => isset($validated['from']) ? (string) $validated['from'] : null,
            'to' => isset($validated['to']) ? (string) $validated['to'] : null,
        ];

        /** @var User $user */
        $user = $request->user();
        $teamId = app()->bound('currentTeam') ? app('currentTeam')->id : $user->current_team_id;

        $ocsf = OcsfMapper::classify('audit.exported');
        AuditEntry::create([
            'team_id' => $teamId,
            'user_id' => $user->id,
            'event' => 'audit.exported',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'ip_address' => $request->ip(),
            'properties' => [
                'format' => $format,
                'filters' => $filters,
            ],
            'created_at' => now(),
        ]);

        $query = AuditLogQuery::build(
            $filters['search'] ?? '',
            $filters['event'] ?? '',
            $filters['from'],
            $filters['to'],
        )->orderByDesc('id');

        $filename = 'audit-log-'.now()->format('Y-m-d-His').'.'.$format;

        if ($format === 'jsonl') {
            return response()->streamDownload(function () use ($query) {
                foreach ($query->lazy(500) as $entry) {
                    echo json_encode(OcsfMapper::toRecord($entry), JSON_UNESCAPED_SLASHES)."\n";
                }
            }, $filename, ['Content-Type' => 'application/x-ndjson']);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, self::CSV_HEADER, ',', '"', '');

            foreach ($query->lazy(500) as $entry) {
                $createdAt = $entry->getAttribute('created_at');
                $user = $entry->user;

                fputcsv($out, array_map(self::escapeCell(...), [
                    $entry->id,
                    $createdAt instanceof \DateTimeInterface ? $createdAt->format(DATE_ATOM) : $createdAt,
                    $entry->event,
                    $entry->user_id,
                    $user instanceof User ? $user->name : null,
                    $entry->subject_type,
                    $entry->subject_id,
                    $entry->triggered_by,
                    $entry->ip_address,
                    $entry->ocsf_class_uid,
                    $entry->ocsf_severity_id,
                    $entry->properties === null ? null : json_encode($entry->properties, JSON_UNESCAPED_SLASHES),
                ]), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutralise spreadsheet formula injection: prefix cells starting with = + - @ with an apostrophe.
     */
    private static function escapeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
