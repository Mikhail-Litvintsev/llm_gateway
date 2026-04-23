<?php

declare(strict_types=1);

namespace App\Components\Skills;

use App\Components\Skills\Contracts\SkillsRepository;
use App\Components\Skills\Enums\PrebuiltSkill;
use App\Models\Client;
use App\Models\ClientSkill;
use RuntimeException;

final readonly class SkillsOrchestrator
{
    public function __construct(
        private SkillsRepository $repository,
    ) {}

    public function createPrebuilt(Client $client, string $name): ClientSkill
    {
        $this->assertSkillsEnabled($client);

        $prebuilt = PrebuiltSkill::tryFrom($name);
        if ($prebuilt === null) {
            throw new RuntimeException("Unknown prebuilt skill: '$name'", 400);
        }

        return $this->repository->create(
            clientId: (int) $client->id,
            name: $prebuilt->value,
            isPrebuilt: true,
        );
    }

    /** @return ClientSkill[] */
    public function listForClient(Client $client): array
    {
        $this->assertSkillsEnabled($client);

        return $this->repository->listForClient((int) $client->id);
    }

    public function findBySkillId(string $skillId): ?ClientSkill
    {
        return $this->repository->findBySkillId($skillId);
    }

    public function delete(Client $client, string $skillId): void
    {
        $this->assertSkillsEnabled($client);

        $skill = $this->repository->findBySkillId($skillId);

        if ($skill === null || $skill->is_deleted) {
            throw new RuntimeException('Skill not found', 404);
        }

        if ($skill->client_id !== (int) $client->id) {
            throw new RuntimeException('Skill not found', 404);
        }

        $this->repository->softDelete($skill);
    }

    private function assertSkillsEnabled(Client $client): void
    {
        $allowedFeatures = $client->allowed_features ?? [];
        if (!($allowedFeatures['skills'] ?? false)) {
            throw new RuntimeException('Skills feature is not enabled for this client', 403);
        }
    }
}
