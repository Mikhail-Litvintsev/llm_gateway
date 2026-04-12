<?php

namespace App\Providers;

use App\Components\Auth\KeyHasher;
use App\Components\Claude\Beta\BetaHeaderRegistry;
use App\Components\Validation\MessageRequestValidator;
use Illuminate\Support\ServiceProvider;
use Opis\JsonSchema\Validator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BetaHeaderRegistry::class, fn () => new BetaHeaderRegistry(
            config('llm.claude.beta_headers')
        ));

        $this->app->singleton(MessageRequestValidator::class, function () {
            $validator = new Validator();
            $schemasPath = app_path('Components/Validation/Schemas');

            $validator->resolver()->registerFile(
                'urn:gateway:message_request',
                $schemasPath . '/message_request.json',
            );
            $validator->resolver()->registerFile(
                'urn:gateway:batch_item',
                $schemasPath . '/batch_item.json',
            );

            return new MessageRequestValidator($validator);
        });

        $this->app->singleton(KeyHasher::class, fn () => new KeyHasher(
            (string) config('llm.auth.api_key_pepper', '')
        ));
    }

    public function boot(): void
    {
        //
    }
}
