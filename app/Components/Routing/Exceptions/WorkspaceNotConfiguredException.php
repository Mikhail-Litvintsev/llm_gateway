<?php

declare(strict_types=1);

namespace App\Components\Routing\Exceptions;

final class WorkspaceNotConfiguredException extends \RuntimeException
{
    public function __construct(?int $id)
    {
        $label = $id !== null ? (string) $id : 'unassigned';
        parent::__construct("Workspace {$label} is not configured (missing or empty API key)");
    }
}
