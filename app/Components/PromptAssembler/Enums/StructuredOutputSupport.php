<?php

namespace App\Components\PromptAssembler\Enums;

enum StructuredOutputSupport: string
{
    case Native = 'native';
    case JsonObjectOnly = 'json_object_only';
    case None = 'none';
}
