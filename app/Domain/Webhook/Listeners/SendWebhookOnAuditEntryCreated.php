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
 * Streams each new team-scoped audit entry, as its OCSF record (the same shape
 * as the JSON Lines export), to the team's endpoints that subscribe to
 * audit.entry.created by name. The '*' wildcard does not include this event.
 *
 * Wired to the AuditEntry `created` model event. Nothing here writes an audit
 * entry, so there is no feedback loop.
 */
class SendWebhookOnAuditEntryCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Drop the job quietly if the entry is gone before it runs. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public AuditEntry $entry,
    ) {}

    /**
     * Queue the delivery only when the entry's team has an active endpoint
     * subscribed to the audit stream, so most audit writes cost one query.
     */
    public static function queueFor(AuditEntry $entry): void
    {
        if (empty($entry->team_id)) {
            return;
        }

        $subscribed = WebhookEndpoint::withoutGlobalScopes()
            ->where('team_id', $entry->team_id)
            ->active()
            ->get(['id', 'events'])
            ->contains(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo(WebhookEvent::AuditEntryCreated->value));

        if (! $subscribed) {
            return;
        }

        self::dispatch($entry)->afterCommit();
    }

    public function handle(SendWebhookAction $send): void
    {
        if (empty($this->entry->team_id)) {
            return;
        }

        $entry = AuditEntry::withoutGlobalScopes()->find($this->entry->id);
        if (! $entry instanceof AuditEntry) {
            return;
        }

        try {
            $send->execute(WebhookEvent::AuditEntryCreated->value, OcsfMapper::toRecord($entry), (string) $entry->getAttribute('team_id'));
        } catch (Throwable $e) {
            // A webhook failure must never affect the audited action.
            report($e);
        }
    }
}
