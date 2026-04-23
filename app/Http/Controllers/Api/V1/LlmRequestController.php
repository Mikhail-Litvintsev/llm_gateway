<?php

namespace App\Http\Controllers\Api\V1;

use App\Components\RequestPipeline\Exceptions\ValidationException;
use App\Components\RequestPipeline\Exceptions\XmlParseException;
use App\Components\RequestPipeline\RequestPipeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmRequestController extends Controller
{
    public function __construct(
        private readonly RequestPipeline $pipeline,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $client = $request->attributes->get('api_client');

        if (!str_contains($request->header('Content-Type', ''), 'application/xml')) {
            return $this->errorResponse('INVALID_CONTENT_TYPE', 'Content-Type must be application/xml.', 415);
        }

        if ($request->header('Content-Length', 0) > config('llm.max_payload_size')) {
            return $this->errorResponse('PAYLOAD_TOO_LARGE', 'Request body exceeds maximum allowed size.', 413);
        }

        try {
            $result = $this->pipeline->accept(
                xmlBody: $request->getContent(),
                client: $client,
                idempotencyKey: $request->header('X-Idempotency-Key'),
                clientRequestId: $request->header('X-Request-Id'),
                ipAddress: $request->ip(),
            );

            return response()->json($result, 202);
        } catch (XmlParseException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), 400, $e->details);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    private function errorResponse(string $code, string $message, int $httpStatus, array $details = []): JsonResponse
    {
        $body = [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details) {
            $body['error']['details'] = $details;
        }

        return response()->json($body, $httpStatus);
    }
}
