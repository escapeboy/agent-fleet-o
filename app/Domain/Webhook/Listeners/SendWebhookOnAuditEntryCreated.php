<?php

namespace App\Domain\Webhook\Listeners;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Webhook\Actions\SendWebhookAction;
use App\Domain\Webhook\Enums\WebhookEvent;
use App\Domain\Webhook\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Streams each new team audit entry, as its OCSF record (the same shape as the
 * JSON Lines export), to the team's endpoints subscribed to audit.entry.created.
 *
 * The event is opt-in: '*' endpoints do not receive it. The job is only queued
 * when the team has at least one active endpoint that names the event.
 */
class SendWebhookOnAuditEntryCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public AuditEntry $entry,
    ) {}

    public static function queueFor(AuditEntry $entry): void
    {
        $teamId = $entry->getAttribute('team_id');
        if (empty($teamId)) {
            return;
        }

        $event = WebhookEvent::AuditEntryCreated->value;

        $subscribed = WebhookEndpoint::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->active()
            ->get(['id', 'events'])
            ->contains(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event));

        if (! $subscribed) {
            return;
        }

        self::dispatch($entry)->afterCommit();
    }

    public function handle(SendWebhookAction $send): void
    {
        $teamId = $this->entry->getAttribute('team_id');
        if (empty($teamId)) {
            return;
        }

        $entry = AuditEntry::withoutGlobalScopes()->find($this->entry->getKey());
        if (! $entry instanceof AuditEntry) {
            return;
        }

        try {
            $send->execute(WebhookEvent::AuditEntryCreated->value, OcsfMapper::toRecord($entry), (string) $teamId);
        } catch (Throwable $e) {
            // A delivery failure must never affect the audited action.
            report($e);
        }
    }
}
