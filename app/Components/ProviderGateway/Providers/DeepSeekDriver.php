<?php

namespace App\Components\ProviderGateway\Providers;

use App\Components\ProviderGateway\Enums\ProviderName;

class DeepSeekDriver extends OpenAiDriver
{
    public function name(): ProviderName
    {
        return ProviderName::DeepSeek;
    }
}
