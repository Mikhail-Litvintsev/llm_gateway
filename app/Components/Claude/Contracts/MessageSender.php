<?php

declare(strict_types=1);

namespace App\Components\Claude\Contracts;

use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\DTO\SendMessageOutput;
use Illuminate\Http\Client\ConnectionException;

interface MessageSender
{
    /**
     * @throws ConnectionException
     * @throws \JsonException
     */
    public function sendMessage(SendMessageInput $input): SendMessageOutput;
}
