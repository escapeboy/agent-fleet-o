<?php

namespace App\Domain\Credential\Observers;

use App\Domain\Credential\Jobs\CredentialScanJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Dispatches CredentialScanJob when scannable text fields are saved on a model.
 *
 * Register this observer in AppServiceProvider for each model that may contain
 * accidentally embedded secrets: Skill, Agent, WorkflowNode.
 *
 * The observer is intentionally generic — it introspects the model via the
 * $scannableFields property (array of column names) that each registered model
 * must define. If $scannableFields is absent, no scan is dispatched.
 *
 * Uses separate created/updated hooks (not the combined saved hook) to avoid
 * the wasRecentlyCreated flag persisting on in-memory model instances across
 * multiple save calls in the same request lifecycle.
 */
class SecretScanObserver
{
    /**
     * Handle the model "created" event — scan all non-empty scannable fields.
     */
    public function created(Model $model): void
    {
        $this->dispatchIfNeeded($model, $model->scannableFields ?? []);
    }

    /**
     * Handle the model "updated" event — only scan fields that actually changed.
     */
    public function updated(Model $model): void
    {
        /** @var array<int, string> $allFields */
        $allFields = $model->scannableFields ?? [];

        // Only scan fields that were part of this particular update.
        $changedFields = array_values(
            array_filter($allFields, fn (string $f) => $model->wasChanged($f)),
        );

        $this->dispatchIfNeeded($model, $changedFields);
    }

    /**
     * Collect non-empty text for the given fields and dispatch the scan job if any found.
     *
     * @param  array<int, string>  $fieldsToCheck
     */
    private function dispatchIfNeeded(Model $model, array $fieldsToCheck): void
    {
        if (empty($fieldsToCheck)) {
            return;
        }

        // Collect non-empty string content for each field.
        $textFields = [];
        foreach ($fieldsToCheck as $field) {
            $value = $model->getAttribute($field);
            if (is_string($value) && $value !== '') {
                $textFields[$field] = $value;
            }
        }

        if (empty($textFields)) {
            return;
        }

        // Build a stable hash of the field content for Redis deduplication.
        $contentHash = sha1(implode('|', $textFields));

        // Resolve team_id — some models (e.g. WorkflowNode) store it via a parent relation.
        $teamId = $model->team_id
            ?? $model->workflow?->team_id
            ?? null;

        if (! $teamId) {
            return;
        }

        // Resolve the morph-map key or fall back to the fully-qualified class name.
        $subjectType = array_search(get_class($model), Relation::morphMap())
            ?: get_class($model);

        CredentialScanJob::dispatch(
            $teamId,
            $subjectType,
            $model->getKey(),
            $textFields,
            $contentHash,
        );
    }
}
