<?php

namespace Tests\Unit\Domain\Decision;

use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\DTOs\NoulAnswer;
use App\Domain\Decision\DTOs\ScoreAnswer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AnswerDtoTest extends TestCase
{
    #[Test]
    public function choice_exposes_value_probabilities_and_confidence(): void
    {
        $answer = new ChoiceAnswer('billing', ['billing' => 0.9, 'other' => 0.1], 0.8);

        $this->assertSame('choice', $answer->type());
        $this->assertSame('billing', $answer->value());
        $this->assertSame(['billing' => 0.9, 'other' => 0.1], $answer->probabilities());
        $this->assertSame(0.8, $answer->confidence());
        $this->assertSame([
            'type' => 'choice',
            'choice' => 'billing',
            'probabilities' => ['billing' => 0.9, 'other' => 0.1],
            'confidence' => 0.8,
        ], $answer->toArray());
    }

    #[Test]
    public function a_driver_without_a_distribution_leaves_both_null(): void
    {
        $answer = new ChoiceAnswer('billing');

        $this->assertNull($answer->probabilities());
        $this->assertNull($answer->confidence());
    }

    #[Test]
    public function score_keeps_the_legend(): void
    {
        $answer = new ScoreAnswer(1.05, ['0' => 0.0, '1' => 0.95, '2' => 0.05], 0.92, ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry']);

        $this->assertSame('score', $answer->type());
        $this->assertSame(1.05, $answer->value());
        $this->assertSame('Frustrated', $answer->legend['1']);
    }

    #[Test]
    public function noul_has_no_distribution_or_confidence(): void
    {
        $answer = new NoulAnswer(0.95);

        $this->assertSame('noul', $answer->type());
        $this->assertSame(0.95, $answer->value());
        $this->assertNull($answer->probabilities());
        $this->assertNull($answer->confidence());
        $this->assertSame(['type' => 'noul', 'noul' => 0.95], $answer->toArray());
    }

    #[Test]
    public function result_carries_the_model_tokens_and_latency_the_report_needs(): void
    {
        $result = new DecisionResult(
            answers: ['department' => new ChoiceAnswer('billing')],
            model: 'jev-1.13.0',
            inputTokens: 318,
            latencyMs: 42,
        );

        $this->assertSame('jev-1.13.0', $result->model);
        $this->assertSame(318, $result->inputTokens);
        $this->assertSame(42, $result->latencyMs);
        $this->assertSame('billing', $result->answer('department')?->value());
        $this->assertNull($result->answer('nope'));
        $this->assertSame('billing', $result->toArray()['answers']['department']['choice']);
    }
}
