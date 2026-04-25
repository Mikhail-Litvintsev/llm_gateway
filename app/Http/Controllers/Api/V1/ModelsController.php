<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Routing\WorkspaceResolver;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class ModelsController extends Controller
{
    public function __construct(
        private readonly WorkspaceResolver $workspaces,
    ) {}

    /**
     * @return JsonResponse List of available models filtered by client permissions.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('auth.client');
        $live = $request->query('live') === 'true';

        $aliases = config('llm.claude.model_aliases', []);
        $capabilities = config('llm.claude.model_capabilities', []);
        $pricing = config('llm.claude.pricing', []);
        $allowed = $client->allowed_features['models'] ?? null;

        $data = [];
        foreach ($aliases as $alias => $snapshot) {
            if (is_array($allowed) && ! in_array($alias, $allowed, true)) {
                continue;
            }

            $entry = [
                'id' => $alias,
                'type' => 'model',
                'display_name' => $alias,
                'created_at' => null,
                'snapshot' => $snapshot,
                'capabilities' => $capabilities[$alias] ?? [],
                'pricing' => $pricing[$alias] ?? [],
            ];

            if ($live) {
                $entry['live_capabilities'] = $this->fetchLive($snapshot, $client);
            }

            $data[] = $entry;
        }

        $lastEntry = end($data);

        return response()->json([
            'data' => $data,
            'has_more' => false,
            'first_id' => $data[0]['id'] ?? null,
            'last_id' => $lastEntry !== false ? $lastEntry['id'] : null,
        ])
            ->header('Cache-Control', 'private, max-age=60')
            ->header('X-Gateway-Request-Id', Str::uuid()->toString());
    }

    /**
     * @return JsonResponse Single model entry or Anthropic-style 404 error.
     */
    public function show(Request $request, string $alias): JsonResponse
    {
        $aliases = config('llm.claude.model_aliases', []);

        if (! isset($aliases[$alias])) {
            return response()->json([
                'type' => 'error',
                'error' => [
                    'type' => 'not_found_error',
                    'message' => 'unknown model alias',
                ],
            ], 404)
                ->header('X-Gateway-Request-Id', Str::uuid()->toString());
        }

        /** @var Client $client */
        $client = $request->attributes->get('auth.client');
        $allowed = $client->allowed_features['models'] ?? null;

        if (is_array($allowed) && ! in_array($alias, $allowed, true)) {
            return response()->json([
                'type' => 'error',
                'error' => [
                    'type' => 'not_found_error',
                    'message' => 'unknown model alias',
                ],
            ], 404)
                ->header('X-Gateway-Request-Id', Str::uuid()->toString());
        }

        $snapshot = $aliases[$alias];
        $capabilities = config('llm.claude.model_capabilities', []);
        $pricing = config('llm.claude.pricing', []);
        $live = $request->query('live') === 'true';

        $entry = [
            'id' => $alias,
            'type' => 'model',
            'display_name' => $alias,
            'created_at' => null,
            'snapshot' => $snapshot,
            'capabilities' => $capabilities[$alias] ?? [],
            'pricing' => $pricing[$alias] ?? [],
        ];

        if ($live) {
            $entry['live_capabilities'] = $this->fetchLive($snapshot, $client);
        }

        return response()->json($entry)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('X-Gateway-Request-Id', Str::uuid()->toString());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLive(string $snapshot, Client $client): ?array
    {
        $cacheKey = "claude:models_live:$snapshot";

        return Cache::remember($cacheKey, 3600, function () use ($snapshot, $client): ?array {
            $workspace = $this->workspaces->resolveForClient($client);

            try {
                $response = Http::withHeaders([
                    'x-api-key' => $workspace->apiKey,
                    'anthropic-version' => config('llm.claude.anthropic_version'),
                ])
                    ->timeout(3)
                    ->get(rtrim(config('llm.claude.endpoints.models'), '/').'/'.$snapshot);

                return $response->successful() ? $response->json() : null;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
