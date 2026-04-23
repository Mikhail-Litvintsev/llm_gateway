<?php

declare(strict_types=1);

namespace App\Components\Claude\Enums;

enum ServerToolFeature: string
{
    case WebSearch = 'web_search';
    case WebFetch = 'web_fetch';
    case CodeExecution = 'code_execution';
    case ToolSearch = 'tool_search';
    case Memory = 'memory';
    case Bash = 'bash';
    case TextEditor = 'text_editor';
    case ComputerUse = 'computer_use';
}
