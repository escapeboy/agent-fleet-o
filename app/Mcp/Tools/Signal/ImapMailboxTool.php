<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signal;

use App\Models\Connector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Webklex\PHPIMAP\ClientManager;

#[IsReadOnly]
#[IsIdempotent]
class ImapMailboxTool extends Tool
{
    protected string $name = 'imap_mailbox';

    protected string $description = 'Directly access IMAP mailboxes — search emails, read a specific email by UID, or list available folders. '
        .'Use this when you need to proactively query an inbox rather than waiting for poll-based signals. '
        .'This tool is read-only and never modifies mailbox state.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform: search | read | list_folders')
                ->enum(['search', 'read', 'list_folders'])
                ->required(),
            'connector_id' => $schema->string()
                ->description('IMAP connector UUID. Use inbound_connector_manage(list_connectors) to discover configured accounts.')
                ->required(),
            'folder' => $schema->string()
                ->description('Mailbox folder to operate on (default: INBOX)'),
            // search params
            'from' => $schema->string()
                ->description('Filter by sender email address (search only)'),
            'subject' => $schema->string()
                ->description('Filter by subject keyword (search only)'),
            'since' => $schema->string()
                ->description('ISO 8601 date — return emails received since this date, e.g. 2026-03-01 (search only)'),
            'unseen_only' => $schema->boolean()
                ->description('Return only unread/unseen emails (search only)')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Maximum emails to return, 1–50 (default 20)')
                ->default(20),
            // read params
            'uid' => $schema->integer()
                ->description('Email UID to fetch (read only)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $connectorId = $request->get('connector_id');
        if (! $connectorId) {
            return Response::error('connector_id is required');
        }

        $connector = Connector::where('id', $connectorId)
            ->where('driver', 'imap')
            ->first();

        if (! $connector) {
            return Response::error("IMAP connector {$connectorId} not found. Use inbound_connector_manage(list_connectors) to list available connectors.");
        }

        try {
            $client = $this->buildClient($connector->config);
            $client->connect();

            $result = match ($request->get('action')) {
                'search' => $this->handleSearch($client, $request, $connector->config),
                'read' => $this->handleRead($client, $request, $connector->config),
                'list_folders' => $this->handleListFolders($client),
                default => null,
            };

            $client->disconnect();

            if ($result === null) {
                return Response::error('Unknown action. Valid actions: search, read, list_folders');
            }

            return $result;
        } catch (\Throwable $e) {
            return Response::error('IMAP error: '.$e->getMessage());
        }
    }

    private function handleSearch($client, Request $request, array $config): Response
    {
        $folder = $request->get('folder') ?: ($config['folder'] ?? 'INBOX');
        $limit = min((int) ($request->get('limit') ?? 20), 50);

        $mailFolder = $client->getFolder($folder);
        if (! $mailFolder) {
            return Response::error("Folder '{$folder}' not found");
        }

        $query = $mailFolder->messages();

        if ($request->get('unseen_only')) {
            $query->unseen();
        }

        if ($from = $request->get('from')) {
            $query->from($from);
        }

        if ($subject = $request->get('subject')) {
            $query->subject($subject);
        }

        if ($since = $request->get('since')) {
            try {
                $date = new \DateTime($since);
                $query->since($date);
            } catch (\Throwable) {
                return Response::error("Invalid 'since' date format. Use ISO 8601, e.g. 2026-03-01");
            }
        }

        $messages = $query->setFetchBody(false)->limit($limit)->get();

        $results = $messages->map(function ($message) {
            return [
                'uid' => $message->getUid(),
                'from' => (string) ($message->getFrom()?->first()?->mail ?? ''),
                'subject' => (string) $message->getSubject(),
                'date' => $message->getDate()?->format('c'),
                'seen' => $message->getFlags()->contains('Seen'),
                'snippet' => mb_substr((string) $message->getTextBody(), 0, 200),
            ];
        })->values()->toArray();

        return Response::text(json_encode([
            'folder' => $folder,
            'count' => count($results),
            'emails' => $results,
        ]));
    }

    private function handleRead($client, Request $request, array $config): Response
    {
        $uid = $request->get('uid');
        if (! $uid) {
            return Response::error("'uid' is required for the read action");
        }

        $folder = $request->get('folder') ?: ($config['folder'] ?? 'INBOX');
        $mailFolder = $client->getFolder($folder);
        if (! $mailFolder) {
            return Response::error("Folder '{$folder}' not found");
        }

        $messages = $mailFolder->messages()->whereUid((string) $uid)->get();
        $message = $messages->first();

        if (! $message) {
            return Response::error("Email with UID {$uid} not found in folder '{$folder}'");
        }

        $body = $message->getTextBody();
        if (empty($body)) {
            $body = strip_tags((string) $message->getHTMLBody());
        }

        $attachments = $message->getAttachments()->map(function ($att) {
            return [
                'filename' => (string) $att->getName(),
                'mime' => (string) $att->getMimeType(),
                'size' => $att->getSize(),
            ];
        })->toArray();

        return Response::text(json_encode([
            'uid' => $message->getUid(),
            'message_id' => (string) $message->getMessageId(),
            'subject' => (string) $message->getSubject(),
            'from' => (string) ($message->getFrom()?->first()?->mail ?? ''),
            'to' => $message->getTo()?->map(fn ($a) => $a->mail)->toArray() ?? [],
            'cc' => $message->getCc()?->map(fn ($a) => $a->mail)->toArray() ?? [],
            'date' => $message->getDate()?->format('c'),
            'seen' => $message->getFlags()->contains('Seen'),
            'body' => mb_substr($body, 0, 50000),
            'attachments' => $attachments,
        ]));
    }

    private function handleListFolders($client): Response
    {
        $folders = $client->getFolders();

        $result = $folders->map(function ($folder) {
            try {
                $total = $folder->messages()->count();
                $unseen = $folder->messages()->unseen()->count();
            } catch (\Throwable) {
                $total = null;
                $unseen = null;
            }

            return [
                'name' => $folder->path,
                'message_count' => $total,
                'unseen_count' => $unseen,
            ];
        })->values()->toArray();

        return Response::text(json_encode([
            'count' => count($result),
            'folders' => $result,
        ]));
    }

    private function buildClient(array $config): mixed
    {
        $cm = new ClientManager;

        return $cm->make([
            'host' => $config['host'],
            'port' => $config['port'] ?? 993,
            'encryption' => $config['encryption'] ?? 'ssl',
            'validate_cert' => true,
            'username' => $config['username'],
            'password' => $config['password'] ?? '',
            'protocol' => 'imap',
        ]);
    }
}
