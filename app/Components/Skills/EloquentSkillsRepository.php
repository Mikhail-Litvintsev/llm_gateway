<?php

declare(strict_types=1);

namespace App\Components\Skills;

use App\Components\Skills\Contracts\SkillsRepository;
use App\Models\ClientSkill;
use Illuminate\Support\Str;

final class EloquentSkillsRepository implements SkillsRepository
{
    public function create(int $clientId, string $name, bool $isPrebuilt, ?string $version = null): ClientSkill
    {
        $skill = new ClientSkill;
        $skill->skill_id = 'skl_'.Str::random(24);
        $skill->client_id = $clientId;
        $skill->name = $name;
        $skill->is_prebuilt = $isPrebuilt;
        $skill->version = $version;
        $skill->save();

        return $skill;
    }

    public function listForClient(int $clientId): array
    {
        return ClientSkill::where('client_id', $clientId)
            ->where('is_deleted', false)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function findBySkillId(string $skillId): ?ClientSkill
    {
        return ClientSkill::where('skill_id', $skillId)->first();
    }

    public function softDelete(ClientSkill $skill): void
    {
        $skill->is_deleted = true;
        $skill->save();
    }
}
