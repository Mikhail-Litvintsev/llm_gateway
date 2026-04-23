<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Skills\SkillsOrchestrator;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SkillsController extends Controller
{
    public function __construct(
        private readonly SkillsOrchestrator $skills,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);
        $type = $request->input('type', '');
        $name = $request->input('name', '');

        if ($type === 'custom') {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'custom_skills_not_yet_supported', 'message' => 'Custom skill upload is not yet supported'],
            ], 501);
        }

        if ($type !== 'prebuilt') {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'invalid_skill_type', 'message' => "Invalid skill type: '$type'"],
            ], 400);
        }

        try {
            $skill = $this->skills->createPrebuilt($client, $name);
        } catch (RuntimeException $e) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'skill_creation_failed', 'message' => $e->getMessage()],
            ], $e->getCode() ?: 500);
        }

        return response()->json([
            'skill_id' => $skill->skill_id,
            'name' => $skill->name,
            'type' => 'prebuilt',
            'is_prebuilt' => true,
            'created_at' => $skill->created_at?->toIso8601String(),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        try {
            $skills = $this->skills->listForClient($client);
        } catch (RuntimeException $e) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'skills_list_failed', 'message' => $e->getMessage()],
            ], $e->getCode() ?: 500);
        }

        return response()->json([
            'data' => array_map(fn ($s) => [
                'skill_id' => $s->skill_id,
                'name' => $s->name,
                'type' => $s->is_prebuilt ? 'prebuilt' : 'custom',
                'is_prebuilt' => $s->is_prebuilt,
                'version' => $s->version,
                'created_at' => $s->created_at?->toIso8601String(),
            ], $skills),
        ]);
    }

    public function show(Request $request, string $skillId): JsonResponse
    {
        $client = $this->resolveClient($request);
        $skill = $this->skills->findBySkillId($skillId);

        if ($skill === null || $skill->is_deleted || $skill->client_id !== (int) $client->id) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'skill_not_found', 'message' => 'Skill not found'],
            ], 404);
        }

        return response()->json([
            'skill_id' => $skill->skill_id,
            'name' => $skill->name,
            'type' => $skill->is_prebuilt ? 'prebuilt' : 'custom',
            'is_prebuilt' => $skill->is_prebuilt,
            'version' => $skill->version,
            'created_at' => $skill->created_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, string $skillId): JsonResponse
    {
        $client = $this->resolveClient($request);

        try {
            $this->skills->delete($client, $skillId);
        } catch (RuntimeException $e) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'skill_delete_failed', 'message' => $e->getMessage()],
            ], $e->getCode() ?: 500);
        }

        return response()->json(null, 204);
    }

    private function resolveClient(Request $request): Client
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        return $client;
    }
}
