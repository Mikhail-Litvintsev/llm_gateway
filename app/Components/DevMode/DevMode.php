<?php

declare(strict_types=1);

namespace App\Components\DevMode;

use App\Components\Claude\DTO\MessageRequest;
use App\Components\Claude\DTO\StreamEvent;
use App\Components\DevMode\DTO\StubbedResponse;
use App\Models\Client;
use Generator;

final class DevMode
{
    public function __construct(
        private readonly DevModeStubber $stubber,
    ) {}

    public function stub(MessageRequest $request, Client $client): StubbedResponse
    {
        return $this->stubber->buildMessageResponse($request, $client);
    }

    /** @return Generator<StreamEvent> */
    public function stubStream(MessageRequest $request, Client $client): Generator
    {
        return $this->stubber->buildStreamEvents($request, $client);
    }
}
