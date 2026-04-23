<?php

namespace App\Components\CallbackDelivery\DTO;

readonly class DeliveryResult
{
    public function __construct(
        public bool $success,
        public int $httpStatus,
        public ?string $error,
    ) {}
}
