<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Claude\Claude;
use App\Components\Claude\Files\FilePurpose;
use App\Components\Claude\Files\FilesUploadHandler;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class FilesController extends Controller
{
    public function __construct(
        private readonly FilesUploadHandler $uploadHandler,
        private readonly Claude $claude,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        if (! $request->hasFile('file')) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'missing_file', 'message' => 'missing_file'],
            ], 400);
        }

        $purposeString = $request->input('purpose');
        $purpose = FilePurpose::tryFrom((string) $purposeString);

        if ($purpose === null) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'invalid_purpose', 'message' => 'Purpose must be one of: vision, document, code_execution_input, other'],
            ], 400);
        }

        try {
            $claudeFile = $this->uploadHandler->upload($client, $request->file('file'), $purpose);
        } catch (RuntimeException $e) {
            $statusCode = $e->getCode() ?: 500;
            $body = json_decode($e->getMessage(), true);

            return response()->json($body ?? ['type' => 'error', 'error' => ['type' => 'upstream_error', 'message' => $e->getMessage()]], $statusCode);
        } catch (ConnectionException $e) {
            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'upstream_timeout', 'message' => 'Connection to Anthropic failed'],
            ], 504);
        }

        return response()->json($claudeFile->toArray(), 201);
    }

    public function show(Request $request, string $fileId): JsonResponse
    {
        $client = $this->resolveClient($request);

        try {
            $file = $this->claude->getFile($fileId, (string) $client->id);
        } catch (RuntimeException $e) {
            return $this->errorFromException($e);
        }

        return response()->json($file->toArray());
    }

    public function destroy(Request $request, string $fileId): Response
    {
        $client = $this->resolveClient($request);

        try {
            $this->claude->deleteFile($fileId, (string) $client->id);
        } catch (RuntimeException $e) {
            return $this->errorFromException($e);
        }

        return response()->noContent();
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        $limit = min((int) $request->query('limit', '50'), 200);
        $cursor = $request->query('cursor');
        $purposeStr = $request->query('purpose');
        $purpose = $purposeStr !== null ? FilePurpose::tryFrom($purposeStr) : null;

        $page = $this->claude->listFiles((string) $client->id, $cursor, $limit, $purpose);

        return response()->json($page->toArray());
    }

    private function resolveClient(Request $request): Client
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        return $client;
    }

    private function errorFromException(RuntimeException $e): JsonResponse
    {
        $statusCode = $e->getCode() ?: 500;
        $body = json_decode($e->getMessage(), true);

        return response()->json(
            $body ?? ['type' => 'error', 'error' => ['type' => 'unknown_error', 'message' => $e->getMessage()]],
            $statusCode,
        );
    }
}
