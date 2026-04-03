<?php

namespace App\Domain\Agent\Enums;

enum AgentReasoningStrategy: string
{
    case FunctionCalling = 'function_calling';
    case ReAct = 'react';
    case ChainOfThought = 'chain_of_thought';
    case PlanAndExecute = 'plan_and_execute';
    case TreeOfThought = 'tree_of_thought';

    public function label(): string
    {
        return match ($this) {
            self::FunctionCalling => 'Function Calling (Default)',
            self::ReAct => 'ReAct (Reason + Act)',
            self::ChainOfThought => 'Chain of Thought',
            self::PlanAndExecute => 'Plan and Execute',
            self::TreeOfThought => 'Tree of Thought',
        };
    }

    public function systemPromptSection(): string
    {
        return match ($this) {
            self::FunctionCalling => '',
            self::ReAct => <<<'PROMPT'
## Reasoning Strategy: ReAct
For each step, explicitly reason before acting:
1. **Thought:** What do I know? What do I need to find out?
2. **Action:** Which tool should I use and why?
3. **Observation:** What did the tool return?
4. **Repeat** until you have enough information to produce a final answer.
Always output your Thought before each tool call.
PROMPT,
            self::ChainOfThought => <<<'PROMPT'
## Reasoning Strategy: Chain of Thought
Think step-by-step before giving your final answer:
1. Break the problem into smaller sub-problems.
2. Solve each sub-problem in sequence, showing your reasoning.
3. Synthesize the sub-results into a final answer.
Prefix your reasoning with "Let me think through this step by step:" before your first tool call or answer.
PROMPT,
            self::PlanAndExecute => <<<'PROMPT'
## Reasoning Strategy: Plan and Execute
Before using any tools, create an explicit plan:
1. **Plan:** List every step needed to complete the task (numbered list).
2. **Execute:** Work through each step in order, checking it off as you go.
3. **Verify:** After all steps are done, review the result against the original task.
Output your plan at the start as "## Execution Plan\n1. ...\n2. ..." before taking any action.
PROMPT,
            self::TreeOfThought => <<<'PROMPT'
## Reasoning Strategy: Tree of Thought
Explore multiple solution paths before committing:
1. Generate 2-3 distinct approaches to the task.
2. Evaluate each approach (pros, cons, feasibility).
3. Select the most promising path and execute it.
4. If the chosen path hits a dead end, backtrack and try the next best option.
Begin with "## Candidate Approaches\n**Approach A:** ...\n**Approach B:** ..." before taking action.
PROMPT,
        };
    }
}
