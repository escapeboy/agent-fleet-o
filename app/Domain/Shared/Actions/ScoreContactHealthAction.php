<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Services\ContactHealthScorer;

class ScoreContactHealthAction
{
    public function __construct(private readonly ContactHealthScorer $scorer) {}

    public function execute(ContactIdentity $contact): void
    {
        $scores = $this->scorer->score($contact);

        $contact->update($scores);
    }
}
