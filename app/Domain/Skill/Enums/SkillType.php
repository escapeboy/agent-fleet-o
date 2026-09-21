<?php

namespace App\Domain\Skill\Enums;

enum SkillType: string
{
    case Llm = 'llm';
    case Connector = 'connector';
    case Rule = 'rule';
    case Hybrid = 'hybrid';
    case Guardrail = 'guardrail';
    case MultiModelConsensus = 'multi_model_consensus';
    case CodeExecution = 'code_execution';
    case Browser = 'browser';
    case RunpodEndpoint = 'runpod_endpoint';
    case RunpodPod = 'runpod_pod';
    case GpuCompute = 'gpu_compute';
    case BorunaScript = 'boruna_script';
    case SupabaseEdgeFunction = 'supabase_edge_function';
    case RagflowRetrieval = 'ragflow_retrieval';
    case Decision = 'decision';

    /**
     * Whether this type's execution path consumes
     * `configuration['prompt_template']`.
     *
     * This is the playground's and the benchmark's precondition: both post that
     * template to the LLM gateway and bill for the call. Reaching the gateway is
     * NOT the same question — `connector` builds its prompt from
     * `configuration['task']` and `rule` from `configuration['rules']`, both via
     * `system_prompt`, so a playground run on either measures a prompt that
     * production never sends and charges the team for it.
     *
     * True only for the arms that route through
     * `ExecuteSkillAction::buildUserPrompt()`.
     */
    public function usesPromptTemplate(): bool
    {
        return match ($this) {
            self::Llm, self::Hybrid, self::Guardrail, self::MultiModelConsensus => true,
            self::Connector, self::Rule,
            self::CodeExecution, self::Browser, self::RunpodEndpoint, self::RunpodPod,
            self::GpuCompute, self::BorunaScript, self::SupabaseEdgeFunction,
            self::RagflowRetrieval, self::Decision => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Llm => 'LLM-Backed',
            self::Connector => 'Connector',
            self::Rule => 'Rule-Based',
            self::Hybrid => 'Hybrid',
            self::Guardrail => 'Guardrail',
            self::MultiModelConsensus => 'Multi-Model Consensus',
            self::CodeExecution => 'Code Execution',
            self::Browser => 'Browser Automation',
            self::RunpodEndpoint => 'RunPod Endpoint',
            self::RunpodPod => 'RunPod Pod',
            self::GpuCompute => 'GPU Compute',
            self::BorunaScript => 'Boruna Script',
            self::SupabaseEdgeFunction => 'Supabase Edge Function',
            self::RagflowRetrieval => 'RAGFlow Retrieval',
            self::Decision => 'Decision Model',
        };
    }
}
