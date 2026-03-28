<?php

namespace App\Domain\Chatbot\Enums;

enum KnowledgeSourceStatus: string
{
    case Pending = 'pending';
    case Indexing = 'indexing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Error = 'error';
}
