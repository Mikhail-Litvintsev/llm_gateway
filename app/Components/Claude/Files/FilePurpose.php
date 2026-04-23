<?php

declare(strict_types=1);

namespace App\Components\Claude\Files;

enum FilePurpose: string
{
    case Vision = 'vision';
    case Document = 'document';
    case CodeExecutionInput = 'code_execution_input';
    case Other = 'other';
}
