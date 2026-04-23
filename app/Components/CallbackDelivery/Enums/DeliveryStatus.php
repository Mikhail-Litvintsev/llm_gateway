<?php

namespace App\Components\CallbackDelivery\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
