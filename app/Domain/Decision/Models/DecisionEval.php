<?php

namespace App\Domain\Decision\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One scored answer: a single question of a single case, answered by a single
 * driver, in a single repeat of a single run.
 *
 * Harness-internal, so no team scope — these rows describe model behaviour on a
 * fixture, never tenant content.
 */
class DecisionEval extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'decision_evals';

    protected $guarded = [];

    protected $casts = [
        'answer' => 'array',
        'probabilities' => 'array',
        'gold' => 'json',
        'correct' => 'boolean',
        'confidence' => 'float',
        'latency_ms' => 'integer',
        'input_tokens' => 'integer',
        'repeat_index' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Gold is stored as JSON because it is not always a label: a Score case's
     * gold is a level index and a Noul case's is a boolean. Scoring and the
     * report only ever compare strings, so normalise here rather than at four
     * call sites.
     */
    public function goldLabel(): string
    {
        /** @var mixed $gold */
        $gold = $this->gold;

        return is_scalar($gold) ? (string) $gold : (string) json_encode($gold);
    }
}
