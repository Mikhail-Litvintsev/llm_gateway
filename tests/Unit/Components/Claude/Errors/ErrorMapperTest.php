<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Errors;

use App\Components\Claude\Errors\ErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    private ErrorMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ErrorMapper;
    }

    public static function httpStatusProvider(): array
    {
        return [
            [400, 'invalid_request'],
            [401, 'authentication_error'],
            [402, 'billing_error'],
            [403, 'permission_error'],
            [404, 'not_found'],
            [409, 'conflict'],
            [413, 'payload_too_large'],
            [429, 'rate_limit'],
            [500, 'upstream_error'],
            [504, 'upstream_timeout'],
            [529, 'overloaded'],
        ];
    }

    #[Test]
    #[DataProvider('httpStatusProvider')]
    public function map_http_status_returns_correct_type(int $status, string $expected): void
    {
        $this->assertSame($expected, $this->mapper->mapHttpStatus($status));
    }

    #[Test]
    public function map_http_status_returns_unknown_for_unmapped_code(): void
    {
        $this->assertSame('unknown', $this->mapper->mapHttpStatus(418));
    }

    #[Test]
    public function map_extracts_type_and_message_from_anthropic_error_body(): void
    {
        $body = json_encode([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'max_tokens must be positive',
            ],
        ]);

        $result = $this->mapper->map(400, $body);

        $this->assertSame('invalid_request_error', $result['type']);
        $this->assertSame('max_tokens must be positive', $result['message']);
    }

    #[Test]
    public function map_falls_back_to_http_status_when_body_is_not_json(): void
    {
        $result = $this->mapper->map(500, 'Internal Server Error');

        $this->assertSame('upstream_error', $result['type']);
        $this->assertSame('Internal Server Error', $result['message']);
    }

    #[Test]
    public function map_uses_empty_error_message_when_body_is_empty(): void
    {
        $result = $this->mapper->map(500, '');

        $this->assertSame('upstream_error', $result['type']);
        $this->assertSame('Empty error response from Anthropic', $result['message']);
    }

    #[Test]
    public function map_falls_back_to_http_status_when_json_has_no_error_key(): void
    {
        $body = json_encode(['status' => 'fail']);

        $result = $this->mapper->map(429, $body);

        $this->assertSame('rate_limit', $result['type']);
    }

    #[Test]
    public function map_falls_back_type_from_http_when_error_type_missing(): void
    {
        $body = json_encode(['error' => ['message' => 'Something went wrong']]);

        $result = $this->mapper->map(401, $body);

        $this->assertSame('authentication_error', $result['type']);
        $this->assertSame('Something went wrong', $result['message']);
    }

    public static function streamErrorProvider(): array
    {
        return [
            ['overloaded_error', 'overloaded'],
            ['api_error', 'upstream_error'],
            ['invalid_request_error', 'invalid_request'],
            ['authentication_error', 'authentication_error'],
            ['permission_error', 'permission_error'],
            ['rate_limit_error', 'rate_limit'],
            ['some_future_error', 'unknown'],
        ];
    }

    #[Test]
    #[DataProvider('streamErrorProvider')]
    public function map_stream_error_event_returns_correct_type(string $errorType, string $expected): void
    {
        $event = ['error' => ['type' => $errorType, 'message' => 'test']];

        $this->assertSame($expected, $this->mapper->mapStreamErrorEvent($event));
    }

    #[Test]
    public function map_stream_error_event_returns_unknown_when_type_missing(): void
    {
        $this->assertSame('unknown', $this->mapper->mapStreamErrorEvent(['error' => []]));
    }
}
