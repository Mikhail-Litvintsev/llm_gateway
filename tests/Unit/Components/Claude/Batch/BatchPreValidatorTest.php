<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Batch;

use App\Components\Claude\Batch\BatchPreValidator;
use App\Components\Claude\DTO\BatchCreateRequest;
use App\Components\Routing\DTO\ResolvedModel;
use App\Components\Routing\ModelResolver;
use App\Components\Validation\DTO\ValidationError;
use App\Components\Validation\DTO\ValidationResult;
use App\Components\Validation\MessageRequestValidator;
use App\Models\Client;
use Illuminate\Http\Exceptions\HttpResponseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-unit')]
final class BatchPreValidatorTest extends TestCase
{
    private MessageRequestValidator $messageValidator;

    private BatchPreValidator $validator;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messageValidator = $this->createMock(MessageRequestValidator::class);

        $modelResolver = $this->createMock(ModelResolver::class);
        $modelResolver->method('resolve')->willReturn(
            new ResolvedModel(alias: 'claude-sonnet', snapshot: 'claude-sonnet-4-6-20250414', capabilities: [], pricing: [])
        );

        $this->validator = new BatchPreValidator($this->messageValidator, $modelResolver);

        $this->client = new Client;
        $this->client->id = 1;
    }

    #[Test]
    public function valid_batch_passes(): void
    {
        $items = $this->makeItems(5);

        $this->messageValidator
            ->method('validate')
            ->willReturn(new ValidationResult([]));

        $request = new BatchCreateRequest(requests: $items);

        $this->validator->validate($request, $this->client);
        $this->assertTrue(true);
    }

    #[Test]
    public function stream_true_fails(): void
    {
        $items = [
            $this->makeItem('item_1', ['stream' => true]),
        ];

        $this->messageValidator
            ->method('validate')
            ->willReturn(new ValidationResult([
                new ValidationError('/stream', 'stream_forbidden', 'Streaming is not allowed in batch_item context'),
            ]));

        $request = new BatchCreateRequest(requests: $items);

        try {
            $this->validator->validate($request, $this->client);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertStringContainsString('stream', strtolower($body['error']['message']));
        }
    }

    #[Test]
    public function duplicate_custom_id_fails(): void
    {
        $items = [
            $this->makeItem('dup_id'),
            $this->makeItem('dup_id'),
        ];

        $request = new BatchCreateRequest(requests: $items);

        try {
            $this->validator->validate($request, $this->client);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertStringContainsString('duplicate', $body['error']['message']);
            $this->assertSame('duplicate_custom_id', $body['error']['type']);
        }
    }

    #[Test]
    public function empty_batch_fails(): void
    {
        $request = new BatchCreateRequest(requests: []);

        try {
            $this->validator->validate($request, $this->client);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertStringContainsString('at least one', $body['error']['message']);
            $this->assertSame('empty_batch', $body['error']['type']);
        }
    }

    #[Test]
    public function too_many_items_fails(): void
    {
        $items = [];
        for ($i = 0; $i < 100_001; $i++) {
            $items[] = ['custom_id' => "item_$i", 'params' => ['model' => 'claude-sonnet', 'messages' => []]];
        }

        $request = new BatchCreateRequest(requests: $items);

        try {
            $this->validator->validate($request, $this->client);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertStringContainsString('100000', $body['error']['message']);
            $this->assertSame('too_many_items', $body['error']['type']);
        }
    }

    #[Test]
    public function invalid_custom_id_chars_fails(): void
    {
        $items = [
            $this->makeItem('bad!@#id'),
        ];

        $request = new BatchCreateRequest(requests: $items);

        try {
            $this->validator->validate($request, $this->client);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertStringContainsString('custom_id', $body['error']['message']);
            $this->assertSame('invalid_custom_id', $body['error']['type']);
        }
    }

    #[Test]
    public function custom_id_too_long_fails(): void
    {
        $longId = str_repeat('a', 65);
        $items = [
            $this->makeItem($longId),
        ];

        $request = new BatchCreateRequest(requests: $items);

        try {
            $this->validator->validate($request, $this->client);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertStringContainsString('custom_id', $body['error']['message']);
            $this->assertSame('invalid_custom_id', $body['error']['type']);
        }
    }

    private function makeItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->makeItem("item_$i");
        }

        return $items;
    }

    private function makeItem(string $customId, array $extraParams = []): array
    {
        return [
            'custom_id' => $customId,
            'params' => array_merge([
                'model' => 'claude-sonnet',
                'max_tokens' => 1024,
                'messages' => [['role' => 'user', 'content' => 'Hello']],
            ], $extraParams),
        ];
    }
}
