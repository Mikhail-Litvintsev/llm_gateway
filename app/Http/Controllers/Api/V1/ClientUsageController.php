<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Usage\UsageReportOrchestrator;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ClientUsageController extends Controller
{
    public function __construct(
        private readonly UsageReportOrchestrator $usage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        try {
            $data = $this->usage->getUsage($client, $request->query());
        } catch (RuntimeException $e) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => $e->getMessage(), 'message' => $e->getMessage()],
            ], $e->getCode() ?: 500);
        }

        return response()->json($data);
    }

    private function resolveClient(Request $request): Client
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        return $client;
    }
}
