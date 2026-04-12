<?php

declare(strict_types=1);

namespace App\Components\Routing\Exceptions;

final class WorkspaceNotConfiguredException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Workspace {$id} is not configured (missing or empty API key)");
    }
}
