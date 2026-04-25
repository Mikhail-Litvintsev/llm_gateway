<?php

declare(strict_types=1);

namespace App\Components\Messaging;

use App\Components\Authorization\Authorization;
use App\Components\Billing\Billing;
use App\Components\Billing\CostEstimator;
use App\Components\Caching\Caching;
use App\Components\Claude\Payload\FeatureDetector;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Messaging\DTO\MessageRequestInput;
use App\Components\Messaging\DTO\PreparedMessageContext;
use App\Components\Messaging\Exceptions\MessageProcessingException;
use App\Components\Routing\ModelResolver;
use App\Components\Validation\MessageRequestValidator;
use App\Components\Validation\ValidationContext;

final readonly class MessageProcessingCommon
{
    public function __construct(
        private MessageRequestValidator $validator,
        private ModelResolver $modelResolver,
        private FeatureDetector $featureDetector,
        private Authorization $authorization,
        private Billing $billing,
        private Caching $caching,
        private PayloadBuilder $payloadBuilder,
        private CostEstimator $costEstimator,
    ) {}

    public function validate(MessageRequestInput $input, ValidationContext $context): void
    {
        $this->validatePayload($input, $context);
        $this->authorizeAndPreCheck($input);
    }

    public function validatePayload(MessageRequestInput $input, ValidationContext $context): void
    {
        $validation = $this->validator->validate($input->payload, $context, $input->client);
        if (! $validation->isValid()) {
            throw MessageProcessingException::validationFailed(
                $validation->errors[0]->message ?? 'Validation failed',
                $input->gatewayRequestId,
            );
        }
    }

    public function authorizeAndPreCheck(MessageRequestInput $input): void
    {
        [$modelAlias, $modelSnapshot] = $this->resolveModel($input);
        $features = $this->resolveFeatures($input);

        $authResult = $this->authorization->authorize($input->client, $modelAlias, $features);
        if (! $authResult->allowed) {
            throw MessageProcessingException::authorizationDenied(
                $authResult,
                $input->gatewayRequestId,
                $modelAlias,
                $modelSnapshot,
            );
        }

        $preCheck = $this->billing->preCheck($input->client);
        if (! $preCheck->decision->isAllowed()) {
            throw MessageProcessingException::billingCapExceeded(
                $input->gatewayRequestId,
                $modelAlias,
                $modelSnapshot,
            );
        }
    }

    public function prepareForClaude(MessageRequestInput $input): PreparedMessageContext
    {
        [$modelAlias, $modelSnapshot] = $this->resolveModel($input);
        $features = $this->resolveFeatures($input);

        $injection = $this->caching->autoInject($input->payload, $modelAlias, $input->client);
        $injectedPayload = $injection->payload;

        $builtPayload = $this->payloadBuilder->build($injectedPayload, $input->client);
        $tokenEstimate = $this->costEstimator->estimateTokens($injectedPayload, $modelAlias);

        return new PreparedMessageContext(
            gatewayRequestId: $input->gatewayRequestId,
            client: $input->client,
            startedAt: $input->startedAt,
            modelAlias: $modelAlias,
            modelSnapshot: $modelSnapshot,
            featuresUsed: $features,
            rawPayload: $input->payload,
            injectedPayload: $injectedPayload,
            builtPayload: $builtPayload,
            tokenEstimate: $tokenEstimate,
        );
    }

    /**
     * @return array{0: string, 1: string} [modelAlias, modelSnapshot]
     */
    public function resolveModelInfo(MessageRequestInput $input): array
    {
        return $this->resolveModel($input);
    }

    /**
     * @return array{0: string, 1: string} [modelAlias, modelSnapshot]
     */
    private function resolveModel(MessageRequestInput $input): array
    {
        $alias = $input->payload['model']
            ?? $input->client->default_model_alias
            ?? config('llm.claude.default_model_alias');
        $resolved = $this->modelResolver->resolve($alias);

        return [$alias, $resolved->snapshot];
    }

    /**
     * @return list<string>
     */
    private function resolveFeatures(MessageRequestInput $input): array
    {
        $features = $this->featureDetector->detect($input->payload);

        return array_values(array_unique([...$features, ...$input->additionalFeatures]));
    }
}
