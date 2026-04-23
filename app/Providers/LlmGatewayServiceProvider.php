<?php

namespace App\Providers;

use App\Components\PromptAssembler\BlockAssembler;
use App\Components\PromptAssembler\DataBlockFormatter;
use App\Components\PromptAssembler\Formatters\ClaudeFormatter;
use App\Components\PromptAssembler\Formatters\GeminiFormatter;
use App\Components\PromptAssembler\Formatters\OpenAiFormatter;
use App\Components\PromptAssembler\ParameterMapper;
use App\Components\PromptAssembler\PromptAssembler;
use App\Components\PromptAssembler\StructuredOutputFallback;
use App\Components\PromptAssembler\StructuredOutputResolver;
use App\Components\PromptAssembler\ToolSchemaBuilder;
use App\Components\ProviderGateway\FallbackExecutor;
use App\Components\ProviderGateway\ProviderGateway;
use App\Components\ProviderGateway\ProviderResolver;
use App\Components\ProviderGateway\Providers\ClaudeDriver;
use App\Components\ProviderGateway\Providers\DeepSeekDriver;
use App\Components\ProviderGateway\Providers\GeminiDriver;
use App\Components\ProviderGateway\Providers\MistralDriver;
use App\Components\ProviderGateway\Providers\OpenAiDriver;
use App\Components\CallbackDelivery\Contracts\CallbackSignerContract;
use App\Components\Security\CallbackSigner;
use Illuminate\Support\ServiceProvider;

class LlmGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromptAssembler::class, function ($app) {
            return new PromptAssembler(
                blockAssembler: new BlockAssembler(new DataBlockFormatter()),
                toolSchemaBuilder: new ToolSchemaBuilder(),
                parameterMapper: new ParameterMapper(),
                claudeFormatter: new ClaudeFormatter(),
                openAiFormatter: new OpenAiFormatter(),
                geminiFormatter: new GeminiFormatter(),
                structuredOutputResolver: new StructuredOutputResolver(),
                structuredOutputFallback: new StructuredOutputFallback(),
            );
        });

        $this->app->singleton(ProviderResolver::class);

        $this->app->singleton(FallbackExecutor::class, function ($app) {
            return new FallbackExecutor(
                resolver: $app->make(ProviderResolver::class),
                assembler: $app->make(PromptAssembler::class),
            );
        });

        $this->app->singleton(ProviderGateway::class, function ($app) {
            return new ProviderGateway(
                resolver: $app->make(ProviderResolver::class),
                fallbackExecutor: $app->make(FallbackExecutor::class),
                drivers: [
                    'claude' => $app->make(ClaudeDriver::class),
                    'openai' => $app->make(OpenAiDriver::class),
                    'deepseek' => $app->make(DeepSeekDriver::class),
                    'gemini' => $app->make(GeminiDriver::class),
                    'mistral' => $app->make(MistralDriver::class),
                ],
            );
        });

        $this->app->bind(CallbackSignerContract::class, CallbackSigner::class);
    }

    public function boot(): void
    {
        //
    }
}
