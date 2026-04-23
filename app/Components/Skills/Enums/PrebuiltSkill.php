<?php

declare(strict_types=1);

namespace App\Components\Skills\Enums;

enum PrebuiltSkill: string
{
    case Xlsx = 'xlsx';
    case Docx = 'docx';
    case Pptx = 'pptx';
    case Pdf = 'pdf';
}
