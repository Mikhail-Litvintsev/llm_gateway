<?php

namespace App\Providers;

use App\Components\Auth\KeyHasher;
use App\Components\Claude\Beta\BetaHeaderRegistry;
use App\Components\Claude\Claude;
use App\Components\Claude\Contracts\MessageSender;
use App\Components\Claude\Payload\FileSourceResolver;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Pricing\CostCalculator;
use App\Components\Routing\ModelResolver;
use App\Components\Sessions\Contracts\SessionsContract;
use App\Components\Sessions\Contracts\SessionStoreContract;
use App\Components\Sessions\Sessions;
use App\Components\Sessions\SessionStore;
use App\Components\Skills\Contracts\SkillsRepository;
use App\Components\Skills\EloquentSkillsRepository;
use App\Components\Usage\UsageReportFetcher;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\Rules\ServerFeaturesRule;
use App\Models\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;
use RuntimeException;

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
        $this->app->bind(MessageSender::class, Claude::class);

        $this->app->singleton(UsageReportFetcher::class, fn () => new UsageReportFetcher(
            $this->app->make(Factory::class),
            (string) config('llm.claude.admin_api_key', ''),
            (string) config('llm.claude.endpoints.usage_report'),
        ));

        $this->app->singleton(PayloadBuilder::class, fn () => new PayloadBuilder(
            $this->app->make(ModelResolver::class),
            $this->app->make(FileSourceResolver::class),
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

            $resolver = $validator->resolver();
            if (! $resolver instanceof SchemaResolver) {
                throw new RuntimeException('Opis JSON Schema resolver is not configured');
            }

            $resolver->registerFile(
                'urn:gateway:message_request',
                $schemasPath.'/message_request.json',
            );
            $resolver->registerFile(
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
        RateLimiter::for('api-client', function (Request $request): Limit {
            $client = $request->attributes->get('auth.client');

            if (! $client instanceof Client) {
                return Limit::none();
            }

            $limit = $client->rate_limit_rpm > 0
                ? $client->rate_limit_rpm
                : (int) config('llm.rate_limit.default_per_minute', 600);

            return Limit::perMinute($limit)
                ->by('client:'.$client->id)
                ->response(fn (Request $request, array $headers): JsonResponse => new JsonResponse(
                    [
                        'error' => [
                            'type' => 'rate_limit_error',
                            'message' => 'Request rate limit exceeded. Retry after the time indicated by the Retry-After header.',
                        ],
                    ],
                    429,
                    $headers,
                ));
        });
    }
}
