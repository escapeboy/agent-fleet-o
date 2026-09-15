<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Queue a steering message for a running experiment. Messages are appended to
 * orchestration_config.steering_queue and consumed IN ORDER by the
 * SteeringInjection middleware before the next LLM call, then removed.
 *
 * "Queued" (this action) and "applied" (the middleware) are separate audit
 * events, mirroring the accepted/applied split of OpenAI's steering API:
 * acceptance means the operator's message is durable, not that the model has
 * seen it yet.
 *
 * The read-modify-write of orchestration_config runs under a row lock, and so
 * does the middleware's removal, so two concurrent steers (or a steer racing
 * the consume) cannot drop a message that was reported as queued.
 */
class SteerExperimentAction
{
    public const MAX_PENDING = 10;

    public const MAX_LENGTH = 2000;

    public function execute(Experiment $experiment, string $message, ?string $userId = null): Experiment
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Steering message cannot be empty.');
        }

        // Cap at a reasonable length to prevent prompt-injection abuse via long payloads.
        $trimmed = mb_substr($trimmed, 0, self::MAX_LENGTH);

        return DB::transaction(function () use ($experiment, $trimmed, $userId): Experiment {
            /** @var Experiment $locked */
            $locked = Experiment::withoutGlobalScopes()->lockForUpdate()->findOrFail($experiment->id);

            $config = $locked->orchestration_config ?? [];
            // Pending count includes a legacy single `steering_message`; the raw
            // queue array must NOT absorb it, or the legacy key would be read twice.
            $pending = self::pendingQueue($config);

            if (count($pending) >= self::MAX_PENDING) {
                throw new \InvalidArgumentException('Steering queue is full ('.self::MAX_PENDING.' pending). Wait for the next LLM call to consume them.');
            }

            $entry = [
                'id' => Str::uuid()->toString(),
                'message' => $trimmed,
                'queued_at' => now()->toIso8601String(),
                'queued_by' => $userId,
            ];

            $config['steering_queue'] = [...array_values($config['steering_queue'] ?? []), $entry];

            $locked->update(['orchestration_config' => $config]);

            $this->logQueued($locked, $entry, count($pending) + 1);

            return $locked->fresh();
        });
    }

    /**
     * Pending queue entries, tolerant of the legacy single-message keys that
     * in-flight experiments may still carry.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{id: string, message: string, queued_at: ?string, queued_by: ?string}>
     */
    public static function pendingQueue(array $config): array
    {
        $queue = [];

        $legacy = $config['steering_message'] ?? null;
        if (is_string($legacy) && $legacy !== '') {
            $queue[] = [
                'id' => 'legacy',
                'message' => $legacy,
                'queued_at' => $config['steering_queued_at'] ?? null,
                'queued_by' => $config['steering_queued_by'] ?? null,
            ];
        }

        foreach ($config['steering_queue'] ?? [] as $item) {
            if (is_array($item) && is_string($item['message'] ?? null) && $item['message'] !== '') {
                $queue[] = [
                    'id' => self::entryId($item),
                    'message' => $item['message'],
                    'queued_at' => $item['queued_at'] ?? null,
                    'queued_by' => $item['queued_by'] ?? null,
                ];
            }
        }

        return $queue;
    }

    /**
     * Stable identity for a queue entry. Entries written by this action always
     * carry an id; anything else (hand-edited JSON) gets a deterministic one so
     * the consumer can still remove it instead of re-injecting it forever.
     *
     * @param  array<string, mixed>  $item
     */
    public static function entryId(array $item): string
    {
        $id = $item['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        return 'anon-'.md5(json_encode([$item['message'] ?? null, $item['queued_at'] ?? null, $item['queued_by'] ?? null]) ?: '');
    }

    /**
     * @param  array{id: string, message: string, queued_at: string, queued_by: ?string}  $entry
     */
    private function logQueued(Experiment $experiment, array $entry, int $queueLength): void
    {
        $ocsf = OcsfMapper::classify('experiment.steering_queued');

        AuditEntry::create([
            'user_id' => $entry['queued_by'],
            'impersonator_id' => session('impersonating_from'),
            'event' => 'experiment.steering_queued',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => Experiment::class,
            'subject_id' => $experiment->id,
            'properties' => [
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'steering_id' => $entry['id'],
                'queue_length' => $queueLength,
                'message_length' => mb_strlen($entry['message']),
            ],
            'created_at' => now(),
        ]);
    }
}
