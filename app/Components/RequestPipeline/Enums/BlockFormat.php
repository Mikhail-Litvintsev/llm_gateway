<?php

namespace App\Components\RequestPipeline\Enums;

enum BlockFormat: string
{
    case Text = 'text';
    case Csv = 'csv';
    case Json = 'json';
    case Xml = 'xml';
    case Markdown = 'markdown';
    case Base64 = 'base64';
}
