<?php

declare(strict_types=1);

namespace App\Domain\Experiment\Enums;

enum DeliverableType: string
{
    case LandingPage = 'landing_page';
    case PitchDeck = 'pitch_deck';
    case ContentCalendar = 'content_calendar';
    case FinancialModel = 'financial_model';
    case SopDocument = 'sop_document';
    case SalesSequence = 'sales_sequence';
    case MarketResearch = 'market_research';
    case OkrPlan = 'okr_plan';

    public function label(): string
    {
        return match ($this) {
            self::LandingPage => 'Landing Page',
            self::PitchDeck => 'Pitch Deck',
            self::ContentCalendar => 'Content Calendar',
            self::FinancialModel => 'Financial Model',
            self::SopDocument => 'SOP Document',
            self::SalesSequence => 'Sales Sequence',
            self::MarketResearch => 'Market Research',
            self::OkrPlan => 'OKR Plan',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::LandingPage => 'globe-alt',
            self::PitchDeck => 'presentation-chart-bar',
            self::ContentCalendar => 'calendar-days',
            self::FinancialModel => 'banknotes',
            self::SopDocument => 'clipboard-document-list',
            self::SalesSequence => 'envelope',
            self::MarketResearch => 'magnifying-glass-chart',
            self::OkrPlan => 'flag',
        };
    }

    public function bladePartial(): string
    {
        return 'artifacts.deliverables.'.str_replace('_', '-', $this->value);
    }
}
