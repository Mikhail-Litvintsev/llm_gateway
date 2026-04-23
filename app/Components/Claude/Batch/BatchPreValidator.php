<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Claude\DTO\BatchCreateRequest;
use App\Components\Routing\ModelResolver;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;
use App\Models\Client;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class BatchPreValidator
{
    private const int MAX_ITEMS = 100_000;

    public function __construct(
        private readonly MessageRequestValidator $messageValidator,
        private readonly ModelResolver $modelResolver,
    ) {}

    public function validate(BatchCreateRequest $request, Client $client): void
    {
        $items = $request->requests;

        if ($items === []) {
            $this->fail(400, 'empty_batch', 'Batch must contain at least one request');
        }

        if (count($items) > self::MAX_ITEMS) {
            $this->fail(400, 'too_many_items', 'Batch exceeds maximum of ' . self::MAX_ITEMS . ' items');
        }

        $this->validateCustomIdUniqueness($items);
        $this->validateItems($items, $client);
        $this->validatePayloadSize($items);
    }

    private function validateCustomIdUniqueness(array $items): void
    {
        $seen = [];
        foreach ($items as $index => $item) {
            $customId = $item['custom_id'] ?? '';
            if (isset($seen[$customId])) {
                $this->fail(400, 'duplicate_custom_id', "item {$customId}: duplicate custom_id", $index);
            }
            $seen[$customId] = true;
        }
    }

    private function validateItems(array $items, Client $client): void
    {
        $customIdPattern = '/^[a-zA-Z0-9_-]{1,64}$/';

        foreach ($items as $index => $item) {
            $customId = $item['custom_id'] ?? '';

            if (!preg_match($customIdPattern, $customId)) {
                $this->fail(400, 'invalid_custom_id', "item {$customId}: custom_id must match ^[a-zA-Z0-9_-]{1,64}$", $index);
            }

            $params = $item['params'] ?? [];

            if (!is_array($params) || $params === []) {
                $this->fail(400, 'invalid_request_error', "item {$customId}: params is required and must be a non-empty object", $index);
            }

            $result = $this->messageValidator->validate($item, ValidationContext::BatchItem, $client);

            if (!$result->isValid()) {
                $this->fail(400, 'invalid_request_error', "item {$customId}: " . $result->errors[0]->message, $index);
            }

            $this->modelResolver->resolve($params['model'] ?? config('llm.claude.default_model_alias'));
        }
    }

    private function validatePayloadSize(array $items): void
    {
        $maxBytes = (int) config('llm.max_batch_payload_mb', 256) * 1024 * 1024;
        $size = strlen((string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($size > $maxBytes) {
            $this->fail(413, 'payload_too_large', 'Batch payload exceeds maximum size of ' . config('llm.max_batch_payload_mb') . ' MB');
        }
    }

    /**
     * @throws HttpException
     */
    private function fail(int $status, string $errorType, string $message, ?int $itemIndex = null): never
    {
        $body = [
            'type' => 'error',
            'error' => [
                'type' => $errorType,
                'message' => $message,
            ],
        ];

        if ($itemIndex !== null) {
            $body['error']['item_index'] = $itemIndex;
        }

        abort(response()->json($body, $status));
    }
}
