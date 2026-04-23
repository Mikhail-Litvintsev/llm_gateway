<?php

namespace App\Providers;

use App\Components\Auth\KeyHasher;
use App\Components\Claude\Beta\BetaHeaderRegistry;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Pricing\CostCalculator;
use App\Components\Sessions\Contracts\SessionsContract;
use App\Components\Sessions\Contracts\SessionStoreContract;
use App\Components\Sessions\Sessions;
use App\Components\Sessions\SessionStore;
use App\Components\Skills\Contracts\SkillsRepository;
use App\Components\Skills\EloquentSkillsRepository;
use App\Components\Usage\UsageReportFetcher;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\Rules\ServerFeaturesRule;
use Illuminate\Support\ServiceProvider;
use Opis\JsonSchema\Validator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BetaHeaderRegistry::class, fn () => new BetaHeaderRegistry(
            config('llm.claude.beta_headers')
        ));

        $this->app->singleton(KeyHasher::class, fn () => new KeyHasher(
            (string) config('llm.auth.api_key_pepper', '')
        ));

        $this->app->bind(SessionStoreContract::class, SessionStore::class);
        $this->app->bind(SessionsContract::class, Sessions::class);
        $this->app->bind(SkillsRepository::class, EloquentSkillsRepository::class);

        $this->app->singleton(UsageReportFetcher::class, fn () => new UsageReportFetcher(
            $this->app->make(\Illuminate\Http\Client\Factory::class),
            (string) config('llm.claude.admin_api_key', ''),
            (string) config('llm.claude.endpoints.usage_report'),
        ));

        $this->app->singleton(PayloadBuilder::class, fn () => new PayloadBuilder(
            $this->app->make(\App\Components\Routing\ModelResolver::class),
            $this->app->make(\App\Components\Claude\Payload\FileSourceResolver::class),
            config('llm.claude.beta_headers'),
        ));

        $this->app->singleton(CostCalculator::class, fn () => new CostCalculator(
            config('llm.claude.pricing'),
            (float) config('llm.claude.inference_geo.multiplier', 1.10),
            (float) config('llm.claude.service_tier.priority_multiplier', 1.0),
            (float) config('llm.claude.pricing.fast_multiplier', 6.0),
        ));

        $this->app->singleton(MessageRequestValidator::class, function () {
            $validator = new Validator;
            $schemasPath = app_path('Components/Validation/Schemas');

            $validator->resolver()->registerFile(
                'urn:gateway:message_request',
                $schemasPath.'/message_request.json',
            );
            $validator->resolver()->registerFile(
                'urn:gateway:batch_item',
                $schemasPath.'/batch_item.json',
            );

            return new MessageRequestValidator(
                $validator,
                $this->app->make(ServerFeaturesRule::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
