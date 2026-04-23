<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Claude\Batch\Accumulator\BatchAccumulator;
use App\Components\Claude\Batch\Accumulator\Exceptions\CallbackUrlMismatchException;
use App\Components\Claude\Batch\Accumulator\Exceptions\DuplicateCustomIdException;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MessagesBatchAccumulatorController extends Controller
{
    public function __construct(
        private readonly BatchAccumulator $accumulator,
        private readonly MessageRequestValidator $validator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('auth.client');

        $payload = $request->json()->all();

        $validation = $this->validator->validate($payload, ValidationContext::BatchItem, $client);

        if (!$validation->isValid()) {
            return new JsonResponse([
                'error' => [
                    'type' => 'validation_error',
                    'errors' => array_map(
                        fn ($e) => ['path' => $e->path, 'code' => $e->code, 'message' => $e->message],
                        $validation->errors,
                    ),
                ],
            ], 422);
        }

        $customId = $payload['custom_id'] ?? null;
        $callbackUrl = $payload['callback_url'] ?? null;
        $flushStrategy = $payload['flush_strategy'] ?? 'deferred';

        try {
            $result = $this->accumulator->append($client, $payload, $customId, $callbackUrl, $flushStrategy);
        } catch (DuplicateCustomIdException) {
            return new JsonResponse([
                'error' => [
                    'type' => 'duplicate_custom_id',
                    'message' => 'custom_id already exists in this bucket',
                ],
            ], 409);
        } catch (CallbackUrlMismatchException) {
            return new JsonResponse([
                'error' => [
                    'type' => 'callback_url_mismatch_within_bucket',
                    'message' => 'All items in a bucket must use the same callback_url',
                ],
            ], 400);
        }

        return new JsonResponse($result->toArray(), 202);
    }
}
