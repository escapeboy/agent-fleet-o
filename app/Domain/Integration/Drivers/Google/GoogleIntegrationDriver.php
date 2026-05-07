<?php

namespace App\Domain\Integration\Drivers\Google;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Google Workspace integration driver (Sheets + Calendar + Drive).
 *
 * Unified OAuth2 token covers all three Google APIs.
 * Access tokens expire after 1 hour; refresh via oauth2.googleapis.com/token.
 * Phase 1: polling-based triggers (no webhook push channels).
 */
class GoogleIntegrationDriver implements IntegrationDriverInterface
{
    private const SHEETS_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const CALENDAR_BASE = 'https://www.googleapis.com/calendar/v3';

    private const DRIVE_BASE = 'https://www.googleapis.com/drive/v3';

    private const DOCS_BASE = 'https://docs.googleapis.com/v1/documents';

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Workspace';
    }

    public function description(): string
    {
        return 'Read and write Google Sheets, create Calendar events, and generate Drive documents from agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'password', 'required' => true,  'label' => 'Access Token'],
            'refresh_token' => ['type' => 'password', 'required' => false, 'label' => 'Refresh Token'],
            'expires_at' => ['type' => 'string',   'required' => false, 'label' => 'Token Expiry (ISO 8601)'],
            'email' => ['type' => 'string',   'required' => false, 'label' => 'Google Account Email (display only)'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;
        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        try {
            $token = $this->resolveAccessToken($integration);
        } catch (\Throwable $e) {
            return HealthResult::fail('Token refresh failed: '.$e->getMessage());
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $email = $response->json('email', 'Google Account');

                return HealthResult::ok(
                    latencyMs: $latency,
                    message: "Connected as {$email}",
                    identity: [
                        'label' => $email,
                        'identifier' => $response->json('sub'),
                        'url' => null,
                        'metadata' => array_filter([
                            'name' => $response->json('name'),
                            'picture' => $response->json('picture'),
                            'email_verified' => $response->json('email_verified'),
                        ]),
                    ],
                );
            }

            return HealthResult::fail($response->json('error.message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('new_sheet_row', 'New Sheet Row', 'A new row was appended to a configured Google Sheet.'),
            new TriggerDefinition('calendar_event_starting', 'Calendar Event Starting', 'A calendar event is starting within the configured window.'),
            new TriggerDefinition('new_drive_file', 'New Drive File', 'A new file was added to a watched Drive folder.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('append_sheet_row', 'Append Sheet Row', 'Append a row to a Google Sheets spreadsheet.', [
                'spreadsheet_id' => ['type' => 'string', 'required' => true, 'label' => 'Spreadsheet ID'],
                'sheet_name' => ['type' => 'string', 'required' => true, 'label' => 'Sheet name (tab)'],
                'values' => ['type' => 'array',  'required' => true, 'label' => 'Row values (flat array)'],
            ]),
            new ActionDefinition('update_sheet_row', 'Update Sheet Range', 'Write data to a specific range in a spreadsheet.', [
                'spreadsheet_id' => ['type' => 'string', 'required' => true, 'label' => 'Spreadsheet ID'],
                'range' => ['type' => 'string', 'required' => true, 'label' => 'A1 notation range (e.g. Sheet1!A2:C2)'],
                'values' => ['type' => 'array',  'required' => true, 'label' => '2D array of values'],
            ]),
            new ActionDefinition('read_sheet_range', 'Read Sheet Range', 'Read a range of cells from a Google Sheet.', [
                'spreadsheet_id' => ['type' => 'string', 'required' => true, 'label' => 'Spreadsheet ID'],
                'range' => ['type' => 'string', 'required' => true, 'label' => 'A1 notation range'],
            ]),
            new ActionDefinition('create_calendar_event', 'Create Calendar Event', 'Create a Google Calendar event.', [
                'calendar_id' => ['type' => 'string', 'required' => false, 'label' => 'Calendar ID (default: primary)'],
                'summary' => ['type' => 'string', 'required' => true,  'label' => 'Event title'],
                'start' => ['type' => 'string', 'required' => true,  'label' => 'Start datetime (ISO 8601)'],
                'end' => ['type' => 'string', 'required' => true,  'label' => 'End datetime (ISO 8601)'],
                'description' => ['type' => 'string', 'required' => false, 'label' => 'Event description'],
                'attendees' => ['type' => 'array',  'required' => false, 'label' => 'Attendee emails'],
            ]),
            new ActionDefinition('create_drive_doc', 'Create Drive Document', 'Create a Google Docs document in Drive.', [
                'name' => ['type' => 'string', 'required' => true,  'label' => 'Document name'],
                'content' => ['type' => 'string', 'required' => false, 'label' => 'Initial document text'],
                'folder_id' => ['type' => 'string', 'required' => false, 'label' => 'Parent folder ID'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        try {
            $token = $this->resolveAccessToken($integration);
        } catch (\Throwable) {
            return [];
        }

        $signals = [];

        // Poll new sheet rows
        $spreadsheetId = $integration->config['spreadsheet_id'] ?? null;
        $sheetName = $integration->config['sheet_name'] ?? 'Sheet1';
        $lastRow = (int) ($integration->config['last_row'] ?? 1);

        if ($spreadsheetId) {
            $range = urlencode("{$sheetName}!A".($lastRow + 1).':Z');
            $response = Http::withToken($token)->timeout(15)
                ->get(self::SHEETS_BASE."/{$spreadsheetId}/values/{$range}");

            if ($response->successful()) {
                $rows = $response->json('values') ?? [];
                $newLast = $lastRow + count($rows);

                foreach ($rows as $index => $row) {
                    $signals[] = [
                        'source_type' => 'google',
                        'source_id' => "sheets:{$spreadsheetId}:".($lastRow + $index + 1),
                        'payload' => ['row' => $row, 'row_number' => $lastRow + $index + 1, 'spreadsheet_id' => $spreadsheetId],
                        'tags' => ['google', 'new_sheet_row'],
                    ];
                }

                if ($newLast > $lastRow) {
                    $integration->update(['config' => array_merge($integration->config ?? [], ['last_row' => $newLast])]);
                }
            }
        }

        // Poll upcoming calendar events
        $calendarId = $integration->config['calendar_id'] ?? 'primary';
        $lookAheadMin = (int) ($integration->config['calendar_lookahead_minutes'] ?? 15);
        $lastSynced = $integration->config['calendar_last_synced'] ?? now()->subMinutes($lookAheadMin + 6)->toIso8601String();

        $calResponse = Http::withToken($token)->timeout(15)
            ->get(self::CALENDAR_BASE."/calendars/{$calendarId}/events", [
                'timeMin' => $lastSynced,
                'timeMax' => now()->addMinutes($lookAheadMin)->toIso8601String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 50,
            ]);

        if ($calResponse->successful()) {
            foreach ($calResponse->json('items') ?? [] as $event) {
                $signals[] = [
                    'source_type' => 'google',
                    'source_id' => 'cal:'.$event['id'],
                    'payload' => $event,
                    'tags' => ['google', 'calendar_event_starting'],
                ];
            }
            $integration->update(['config' => array_merge($integration->config ?? [], [
                'calendar_last_synced' => now()->toIso8601String(),
            ])]);
        }

        return $signals;
    }

    public function supportsWebhooks(): bool
    {
        return false; // Phase 1: polling only
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $this->resolveAccessToken($integration);

        return match ($action) {
            'append_sheet_row' => Http::withToken($token)->timeout(15)
                ->post(self::SHEETS_BASE."/{$params['spreadsheet_id']}/values/".urlencode("{$params['sheet_name']}!A1").':append', [
                    'values' => [$params['values']],
                ], ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'])
                ->json(),

            'update_sheet_row' => Http::withToken($token)->timeout(15)
                ->put(self::SHEETS_BASE."/{$params['spreadsheet_id']}/values/".urlencode($params['range']).'?valueInputOption=USER_ENTERED', [
                    'range' => $params['range'],
                    'majorDimension' => 'ROWS',
                    'values' => $params['values'],
                ])->json(),

            'read_sheet_range' => Http::withToken($token)->timeout(15)
                ->get(self::SHEETS_BASE."/{$params['spreadsheet_id']}/values/".urlencode($params['range']))
                ->json(),

            'create_calendar_event' => Http::withToken($token)->timeout(15)
                ->post(self::CALENDAR_BASE.'/calendars/'.($params['calendar_id'] ?? 'primary').'/events', array_filter([
                    'summary' => $params['summary'],
                    'description' => $params['description'] ?? null,
                    'start' => ['dateTime' => $params['start'], 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => $params['end'],   'timeZone' => 'UTC'],
                    'attendees' => isset($params['attendees'])
                        ? array_map(fn ($e) => ['email' => $e], $params['attendees'])
                        : null,
                ]))->json(),

            'create_drive_doc' => $this->createDriveDocument($token, $params),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function createDriveDocument(string $token, array $params): array
    {
        // Create Google Doc via Docs API
        $doc = Http::withToken($token)->timeout(15)
            ->post(self::DOCS_BASE, ['title' => $params['name']])
            ->json();

        $docId = $doc['documentId'] ?? null;

        if ($docId && ! empty($params['content'])) {
            Http::withToken($token)->timeout(15)
                ->post(self::DOCS_BASE."/{$docId}:batchUpdate", [
                    'requests' => [[
                        'insertText' => ['location' => ['index' => 1], 'text' => $params['content']],
                    ]],
                ]);
        }

        // Move to folder if specified
        if ($docId && ! empty($params['folder_id'])) {
            Http::withToken($token)->timeout(15)
                ->patch(self::DRIVE_BASE."/files/{$docId}", [], [
                    'addParents' => $params['folder_id'],
                    'removeParents' => 'root',
                ]);
        }

        return $doc;
    }

    private function resolveAccessToken(Integration $integration): string
    {
        $creds = $integration->credential->secret_data ?? [];
        $expiresAt = $creds['expires_at'] ?? null;
        $accessToken = $creds['access_token'] ?? null;

        if ($accessToken && (! $expiresAt || Carbon::parse($expiresAt)->gt(now()->addMinutes(2)))) {
            return $accessToken;
        }

        $refreshToken = $creds['refresh_token'] ?? null;
        abort_unless($refreshToken, 422, 'Google access token expired and no refresh token available.');

        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('integrations.oauth.google.client_id'),
            'client_secret' => config('integrations.oauth.google.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        abort_unless($response->successful(), 422, 'Google token refresh failed: '.$response->body());

        $newCreds = array_merge($creds, [
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds($response->json('expires_in', 3600))->toIso8601String(),
        ]);

        if ($integration->credential) {
            $integration->credential->update(['secret_data' => $newCreds]);
        }

        return $newCreds['access_token'];
    }
}
