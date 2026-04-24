<?php

namespace App\Domain\Migration\Enums;

enum MigrationSource: string
{
    case Csv = 'csv';
    case Json = 'json';
}
