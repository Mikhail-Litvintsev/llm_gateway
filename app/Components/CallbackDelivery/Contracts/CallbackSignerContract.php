<?php

namespace App\Components\CallbackDelivery\Contracts;

interface CallbackSignerContract
{
    public function sign(string $rawBody, string $signingSecret, string $requestId): array;
}
