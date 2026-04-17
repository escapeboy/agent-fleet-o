<?php

declare(strict_types=1);

namespace App\Domain\Skill\Enums;

enum Framework: string
{
    case RICE = 'rice';
    case SPIN = 'spin';
    case BANT = 'bant';
    case MEDDIC = 'meddic';
    case OKRs = 'okrs';
    case Bullseye = 'bullseye';
    case LeanStartup = 'lean_startup';
    case ShapeUp = 'shape_up';
    case UnitEconomics = 'unit_economics';
    case Kano = 'kano';
    case TamSamSom = 'tam_sam_som';
    case KFactor = 'k_factor';
    case CashFlow = 'cash_flow';
    case NpvIrr = 'npv_irr';
    case RACI = 'raci';
    case LeanOps = 'lean_ops';
    case AbTesting = 'a_b_testing';
    case ThreeDayMvp = 'three_day_mvp';
    case OWASP = 'owasp';
    case BessemerMetrics = 'bessemer_metrics';

    public function label(): string
    {
        return match ($this) {
            self::RICE => 'RICE Scoring',
            self::SPIN => 'SPIN Selling',
            self::BANT => 'BANT Qualification',
            self::MEDDIC => 'MEDDIC',
            self::OKRs => 'OKRs',
            self::Bullseye => 'Bullseye Framework',
            self::LeanStartup => 'Lean Startup',
            self::ShapeUp => 'Shape Up',
            self::UnitEconomics => 'Unit Economics',
            self::Kano => 'Kano Model',
            self::TamSamSom => 'TAM/SAM/SOM',
            self::KFactor => 'K-Factor',
            self::CashFlow => 'Cash Flow',
            self::NpvIrr => 'NPV / IRR',
            self::RACI => 'RACI Matrix',
            self::LeanOps => 'Lean Ops',
            self::AbTesting => 'A/B Testing',
            self::ThreeDayMvp => '3-Day MVP',
            self::OWASP => 'OWASP',
            self::BessemerMetrics => 'Bessemer Metrics',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RICE => 'Score features by Reach, Impact, Confidence, Effort.',
            self::SPIN => 'Sales conversations around Situation, Problem, Implication, Need-payoff.',
            self::BANT => 'Qualify leads by Budget, Authority, Need, Timeline.',
            self::MEDDIC => 'Complex B2B sales qualification: Metrics, Economic Buyer, Decision Criteria, Decision Process, Identify Pain, Champion.',
            self::OKRs => 'Objectives + measurable Key Results, cascaded quarterly.',
            self::Bullseye => 'Traction channel discovery across 19 channels: brainstorm, rank, test.',
            self::LeanStartup => 'Build–Measure–Learn loops with validated learning.',
            self::ShapeUp => 'Fixed-time, variable-scope six-week cycles with shaped work.',
            self::UnitEconomics => 'CAC, LTV, payback period, contribution margin per unit.',
            self::Kano => 'Classify features as basic, performance, delighter.',
            self::TamSamSom => 'Total, Serviceable, Obtainable market sizing.',
            self::KFactor => 'Viral growth coefficient: invites × conversion.',
            self::CashFlow => 'Operating, investing, financing cash flow modelling.',
            self::NpvIrr => 'Net Present Value and Internal Rate of Return for investment decisions.',
            self::RACI => 'Responsibility matrix: Responsible, Accountable, Consulted, Informed.',
            self::LeanOps => 'Waste elimination, continuous flow, pull systems from Lean manufacturing.',
            self::AbTesting => 'Controlled split experiments with significance testing.',
            self::ThreeDayMvp => 'Ship a working prototype in 72 hours of focused build.',
            self::OWASP => 'Top 10 application security risks; secure-by-default patterns.',
            self::BessemerMetrics => 'SaaS benchmarks: ARR, net retention, CAC ratio, magic number.',
        };
    }

    public function category(): FrameworkCategory
    {
        return match ($this) {
            self::RICE, self::LeanStartup, self::Kano, self::TamSamSom => FrameworkCategory::Validation,
            self::SPIN, self::BANT, self::MEDDIC => FrameworkCategory::Sales,
            self::Bullseye, self::KFactor, self::AbTesting => FrameworkCategory::Growth,
            self::UnitEconomics, self::CashFlow, self::NpvIrr, self::BessemerMetrics => FrameworkCategory::Finance,
            self::ShapeUp, self::ThreeDayMvp, self::OWASP => FrameworkCategory::Engineering,
            self::OKRs, self::RACI, self::LeanOps => FrameworkCategory::Operations,
        };
    }
}
