<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sabre\VObject\Reader;

class CalendarConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $icalUrl = $config['ical_url'] ?? null;
        $lookaheadHours = $config['lookahead_hours'] ?? 24;
        $experimentId = $config['experiment_id'] ?? null;
        $tags = $config['tags'] ?? ['calendar'];
        $processedEvents = $config['processed_events'] ?? [];

        if (! $icalUrl) {
            Log::warning('CalendarConnector: No iCal URL provided');

            return [];
        }

        try {
            $response = Http::timeout(30)->get($icalUrl);

            if (! $response->successful()) {
                Log::warning('CalendarConnector: Failed to fetch iCal', [
                    'url' => $icalUrl,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $vcalendar = Reader::read($response->body());

            if ($vcalendar->name !== 'VCALENDAR') {
                Log::warning('CalendarConnector: Invalid iCal format', ['url' => $icalUrl]);

                return [];
            }

            $now = new \DateTimeImmutable;
            $horizon = $now->modify("+{$lookaheadHours} hours");

            $signals = [];
            $newProcessed = $processedEvents;

            foreach ($vcalendar->VEVENT ?? [] as $event) {
                $dtStart = $event->DTSTART?->getDateTime();
                if (! $dtStart) {
                    continue;
                }

                // Only events starting within the lookahead window
                if ($dtStart < $now || $dtStart > $horizon) {
                    continue;
                }

                $uid = (string) ($event->UID ?? '');
                $startKey = $uid.'_'.$dtStart->format('Y-m-d\TH:i:s');

                // Dedup by event UID + start time
                if (in_array($startKey, $processedEvents, true)) {
                    continue;
                }

                $dtEnd = $event->DTEND?->getDateTime();
                $summary = (string) ($event->SUMMARY ?? 'Untitled Event');
                $description = (string) ($event->DESCRIPTION ?? '');
                $location = (string) ($event->LOCATION ?? '');
                $organizer = (string) ($event->ORGANIZER ?? '');

                $payload = [
                    'event_uid' => $uid,
                    'summary' => $summary,
                    'description' => $description,
                    'location' => $location,
                    'organizer' => $organizer,
                    'start' => $dtStart->format('c'),
                    'end' => $dtEnd?->format('c'),
                    'all_day' => ! $event->DTSTART->hasTime(),
                ];

                $signal = $this->ingestAction->execute(
                    sourceType: 'calendar',
                    sourceIdentifier: $icalUrl,
                    payload: $payload,
                    tags: $tags,
                    experimentId: $experimentId,
                );

                if ($signal) {
                    $signals[] = $signal;
                }

                $newProcessed[] = $startKey;
            }

            // Trim processed events to prevent unbounded growth (keep last 500)
            if (count($newProcessed) > 500) {
                $newProcessed = array_slice($newProcessed, -500);
            }

            $config['processed_events'] = $newProcessed;

            return $signals;
        } catch (\Throwable $e) {
            Log::error('CalendarConnector: Error polling calendar', [
                'url' => $icalUrl,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'calendar';
    }

    /**
     * Return the updated config with processed events tracking.
     */
    public function getUpdatedConfig(array $config, array $signals): array
    {
        $processedEvents = $config['processed_events'] ?? [];

        foreach ($signals as $signal) {
            $uid = $signal->payload['event_uid'] ?? '';
            $start = $signal->payload['start'] ?? '';
            if ($uid && $start) {
                $key = $uid.'_'.(new \DateTimeImmutable($start))->format('Y-m-d\TH:i:s');
                if (! in_array($key, $processedEvents, true)) {
                    $processedEvents[] = $key;
                }
            }
        }

        if (count($processedEvents) > 500) {
            $processedEvents = array_slice($processedEvents, -500);
        }

        $config['processed_events'] = $processedEvents;

        return $config;
    }
}
