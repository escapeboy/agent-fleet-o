<?php

namespace App\Domain\KnowledgeGraph\Enums;

enum EntityType: string
{
    case Person = 'person';
    case Company = 'company';
    case Organization = 'organization';
    case Location = 'location';
    case Date = 'date';
    case Product = 'product';
    case Technology = 'technology';
    case Event = 'event';
    case Concept = 'concept';
    case Process = 'process';
    case Topic = 'topic';

    public static function validationRule(): string
    {
        return 'in:'.implode(',', array_column(self::cases(), 'value'));
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromStringOrDefault(string $value): self
    {
        return self::tryFrom($value) ?? self::Topic;
    }

    public function label(): string
    {
        return match ($this) {
            self::Person => 'Person',
            self::Company => 'Company',
            self::Organization => 'Organization',
            self::Location => 'Location',
            self::Date => 'Date',
            self::Product => 'Product',
            self::Technology => 'Technology',
            self::Event => 'Event',
            self::Concept => 'Concept',
            self::Process => 'Process',
            self::Topic => 'Topic',
        };
    }
}
