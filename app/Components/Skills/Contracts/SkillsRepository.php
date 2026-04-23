<?php

declare(strict_types=1);

namespace App\Components\Skills\Contracts;

use App\Models\ClientSkill;

interface SkillsRepository
{
    public function create(int $clientId, string $name, bool $isPrebuilt, ?string $version = null): ClientSkill;

    /** @return ClientSkill[] */
    public function listForClient(int $clientId): array;

    public function findBySkillId(string $skillId): ?ClientSkill;

    public function softDelete(ClientSkill $skill): void;
}
