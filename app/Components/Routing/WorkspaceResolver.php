<?php

declare(strict_types=1);

namespace App\Components\Routing;

use App\Components\Routing\DTO\ResolvedWorkspace;
use App\Components\Routing\Exceptions\WorkspaceNotConfiguredException;
use App\Models\Client;

final class WorkspaceResolver
{
    public function resolveForClient(Client $client): ResolvedWorkspace
    {
        $workspace = $client->workspace;

        if (!$workspace || !$workspace->is_active) {
            throw new WorkspaceNotConfiguredException($client->workspace_id);
        }

        $apiKey = $workspace->decryptedApiKey();

        if ($apiKey === '') {
            throw new WorkspaceNotConfiguredException($workspace->id);
        }

        return new ResolvedWorkspace(
            workspaceId: $workspace->id,
            name: $workspace->name,
            apiKey: $apiKey,
            anthropicWorkspaceId: $workspace->anthropic_workspace_id,
        );
    }
}
