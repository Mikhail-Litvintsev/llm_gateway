<?php

declare(strict_types=1);

namespace App\Components\Claude;

use App\Components\Claude\Beta\BetaHeaderRegistry;
use App\Components\Claude\DTO\Batch;
use App\Components\Claude\DTO\BatchCreateRequest;
use App\Components\Claude\DTO\ClaudeFile;
use App\Components\Claude\DTO\MessageRequest;
use App\Components\Claude\DTO\MessageResponse;
use App\Components\Claude\DTO\ModelInfo;
use App\Components\Claude\DTO\StreamEvent;
use App\Components\Claude\DTO\TokenCountResult;
use App\Components\Claude\Errors\ErrorMapper;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Claude\Response\ResponseParser;
use Generator;
use Illuminate\Http\UploadedFile;

final class Claude
{
    public function __construct(
        private readonly PayloadBuilder $payloadBuilder,
        private readonly ResponseParser $responseParser,
        private readonly ErrorMapper $errorMapper,
        private readonly BetaHeaderRegistry $betaHeaderRegistry,
    ) {}

    public function sendMessage(MessageRequest $request, string $clientId): MessageResponse
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    /** @return Generator<StreamEvent> */
    public function streamMessage(MessageRequest $request, string $clientId): Generator
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function countTokens(MessageRequest $request): TokenCountResult
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function createBatch(BatchCreateRequest $request, string $clientId): Batch
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function getBatch(string $anthropicBatchId): Batch
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    /** @return Generator<DTO\ResultLine> */
    public function getBatchResults(string $anthropicBatchId): Generator
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function uploadFile(UploadedFile $file, string $purpose): ClaudeFile
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function deleteFile(string $anthropicFileId): void
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    /** @return ModelInfo[] */
    public function listModels(): array
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function getModel(string $modelId): ModelInfo
    {
        throw new \LogicException('Not implemented in Phase 1');
    }
}
