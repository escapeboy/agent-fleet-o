<?php

namespace App\Domain\Shared\Enums;

enum KmsConfigStatus: string
{
    case Active = 'active';
    case Testing = 'testing';
    case Error = 'error';
    case Disabled = 'disabled';
}
