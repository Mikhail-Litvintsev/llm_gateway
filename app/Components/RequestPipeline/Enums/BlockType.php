<?php

namespace App\Components\RequestPipeline\Enums;

enum BlockType: string
{
    case System = 'system';
    case Instruction = 'instruction';
    case Description = 'description';
    case Data = 'data';
    case Example = 'example';
    case Constraint = 'constraint';
    case OutputFormat = 'output_format';
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Url = 'url';
    case History = 'history';
    case HistoryToolResult = 'history_tool_result';
    case Prefix = 'prefix';
}
