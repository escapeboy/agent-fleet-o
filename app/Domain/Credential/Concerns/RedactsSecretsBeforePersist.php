<?php

namespace App\Domain\Credential\Concerns;

use App\Domain\Credential\Services\SecretRedactor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redacts secrets from the attributes a model lists in redactableAttributes()
 * when the row is saved, if ai_safety.redact_before_persist is on.
 *
 * Known gaps: query-builder update()/insert()/upsert() bypass model events and
 * are not covered; transcripts already stored by local/bridge agents are not
 * rewritten; embeddings computed before the save still carry the original text.
 *
 * Fail-closed: if redaction throws, the attribute is replaced with a failure
 * marker and the save goes on. The exception is reported, but never logged with
 * content.
 *
 * Models using this trait must declare:
 *   protected function redactableAttributes(): array
 */
trait RedactsSecretsBeforePersist
{
    public static function bootRedactsSecretsBeforePersist(): void
    {
        static::saving(function ($model): void {
            if (! config('ai_safety.redact_before_persist')) {
                return;
            }

            foreach ($model->redactableAttributes() as $attribute) {
                if ($model->isDirty($attribute)) {
                    $model->redactAttributeBeforePersist($attribute);
                }
            }
        });
    }

    protected function redactAttributeBeforePersist(string $attribute): void
    {
        try {
            $original = $this->getAttribute($attribute);
            $result = app(SecretRedactor::class)->redactWithCounts($original);

            if ($result['value'] !== $original) {
                $this->setAttribute($attribute, $result['value']);
            }

            if (array_sum($result['counts']) > 0) {
                Log::info('secret_redacted', [
                    'model' => class_basename($this),
                    'attr' => $attribute,
                    'counts' => $result['counts'],
                ]);
            }
        } catch (Throwable $e) {
            $this->setAttribute($attribute, $this->hasCast($attribute, ['array', 'json', 'collection', 'object', 'encrypted:array'])
                ? ['_redaction_failed' => true]
                : SecretRedactor::FAILED);

            report($e);
        }
    }
}
