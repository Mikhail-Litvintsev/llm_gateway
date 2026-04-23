<?php

namespace App\Components\ProviderGateway\Providers;

use App\Components\ProviderGateway\Enums\ProviderName;

class MistralDriver extends OpenAiDriver
{
    public function name(): ProviderName
    {
        return ProviderName::Mistral;
    }
}
