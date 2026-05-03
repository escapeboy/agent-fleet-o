<?php

namespace App\Domain\AgentSession\Enums;

enum AgentSessionEventKind: string
{
    case Wake = 'wake';
    case Sleep = 'sleep';
    case Transition = 'transition';
    case StageStarted = 'stage_started';
    case StageCompleted = 'stage_completed';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case LlmCall = 'llm_call';
    case HumanInput = 'human_input';
    case Artifact = 'artifact';
    case Error = 'error';
    case HandoffOut = 'handoff_out';
    case HandoffIn = 'handoff_in';
    case Note = 'note';
}
