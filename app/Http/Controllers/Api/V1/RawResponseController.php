<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RequestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RawResponseController extends Controller
{
    private const SENSITIVE_HEADERS = [
        'authorization',
        'x-api-key',
        'api-key',
    ];

    public function show(Request $request, string $requestId): JsonResponse
    {
        $client = $request->attributes->get('api_client');

        if (!$this->isValidRequestId($requestId)) {
            return $this->errorResponse('REQUEST_NOT_FOUND', 'Request not found.', 404);
        }

        $requestLog = RequestLog::where('request_id', $requestId)
            ->forClient($client->id)
            ->first();

        if (!$requestLog) {
            return $this->errorResponse('REQUEST_NOT_FOUND', 'Request not found.', 404);
        }

        $rawResponses = $requestLog->rawResponses()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($raw) => $this->formatRawResponse($raw))
            ->all();

        return response()->json([
            'status' => 'ok',
            'request_id' => $requestId,
            'data' => $rawResponses,
        ]);
    }

    private function isValidRequestId(string $requestId): bool
    {
        return strlen($requestId) <= 256
            && preg_match('/^[a-zA-Z0-9_\-:.]+$/', $requestId);
    }

    private function formatRawResponse($raw): array
    {
        return [
            'id' => $raw->id,
            'provider' => $raw->provider,
            'model' => $raw->model,
            'http_status' => $raw->http_status,
            'response_body' => $raw->response_body,
            'response_headers' => $this->filterSensitiveHeaders($raw->response_headers),
            'is_fallback_attempt' => $raw->is_fallback_attempt,
            'duration_ms' => $raw->duration_ms,
            'created_at' => $raw->created_at?->toIso8601String(),
        ];
    }

    private function filterSensitiveHeaders(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        return array_filter(
            $headers,
            fn (string $key) => !in_array(strtolower($key), self::SENSITIVE_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function errorResponse(string $code, string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $httpStatus);
    }
}
