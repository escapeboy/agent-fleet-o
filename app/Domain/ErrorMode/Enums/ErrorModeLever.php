<?php

namespace App\Domain\ErrorMode\Enums;

/**
 * The system lever a named error mode points at (Diagnose → Improve mapping).
 * Mirrors the SwirlAI flywheel lever taxonomy.
 */
enum ErrorModeLever: string
{
    case Retrieval = 'retrieval';
    case Reranker = 'reranker';
    case Prompt = 'prompt';
    case ToolDescription = 'tool_description';
    case DataPrep = 'data_prep';
    case Guardrails = 'guardrails';
    case ModelRouting = 'model_routing';
    case Finetuning = 'finetuning';
    case Unassigned = 'unassigned';

    public function label(): string
    {
        return match ($this) {
            self::Retrieval => 'Retrieval / RAG',
            self::Reranker => 'Reranker',
            self::Prompt => 'Prompt',
            self::ToolDescription => 'Tool description',
            self::DataPrep => 'Data preparation (chunking, embeddings)',
            self::Guardrails => 'Guardrails / structured output',
            self::ModelRouting => 'Model routing',
            self::Finetuning => 'Finetuning',
            self::Unassigned => 'Unassigned',
        };
    }
}
