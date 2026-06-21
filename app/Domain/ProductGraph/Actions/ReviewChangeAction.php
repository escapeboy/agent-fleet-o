<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use RuntimeException;

/**
 * Human approve/reject of a pending proposal. Approval immediately applies the
 * change to the graph via {@see ApplyApprovedChangeAction}.
 */
class ReviewChangeAction
{
    public function __construct(private readonly ApplyApprovedChangeAction $apply) {}

    public function execute(
        ProductGraphChange $change,
        bool $approve,
        ?string $reviewerUserId = null,
        ?string $note = null,
    ): ProductGraphChange {
        if ($change->status !== ChangeStatus::Pending) {
            throw new RuntimeException('Only pending changes can be reviewed.');
        }

        if (! $approve) {
            $change->update([
                'status' => ChangeStatus::Rejected->value,
                'reviewed_by_user_id' => $reviewerUserId,
                'review_note' => $note,
            ]);

            return $change->refresh();
        }

        $change->update([
            'status' => ChangeStatus::Approved->value,
            'reviewed_by_user_id' => $reviewerUserId,
            'review_note' => $note,
        ]);

        return $this->apply->execute($change->refresh());
    }
}
