<?php

declare(strict_types=1);

namespace App\Components\Routing;

use App\Components\Routing\DTO\ResolvedModel;
use App\Components\Routing\DTO\ResolvedWorkspace;
use App\Models\Client;

final class Routing
{
    public function __construct(
        private readonly ModelResolver $modelResolver,
        private readonly WorkspaceResolver $workspaceResolver,
    ) {}

    public function resolveModel(string $alias): ResolvedModel
    {
        return $this->modelResolver->resolve($alias);
    }

    public function resolveWorkspace(Client $client): ResolvedWorkspace
    {
        return $this->workspaceResolver->resolveForClient($client);
    }
}
