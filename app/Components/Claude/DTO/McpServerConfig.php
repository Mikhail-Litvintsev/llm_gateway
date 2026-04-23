<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

use InvalidArgumentException;

final readonly class McpServerConfig
{
    public function __construct(
        public string $type,
        public string $url,
        public string $name,
        public ?string $authorizationToken = null,
        public ?array $defaultConfig = null,
        public ?array $configs = null,
    ) {
        if ($this->type !== 'url') {
            throw new InvalidArgumentException("MCP server type must be 'url', got '$this->type'");
        }

        if (! str_starts_with($this->url, 'https://')) {
            throw new InvalidArgumentException("MCP server URL must use HTTPS: $this->url");
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $this->name)) {
            throw new InvalidArgumentException("MCP server name must match ^[a-zA-Z0-9_-]+\$: $this->name");
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
            url: $data['url'] ?? '',
            name: $data['name'] ?? '',
            authorizationToken: $data['authorization_token'] ?? null,
            defaultConfig: $data['default_config'] ?? null,
            configs: $data['configs'] ?? null,
        );
    }

    public function toPayload(): array
    {
        $payload = [
            'type' => $this->type,
            'url' => $this->url,
            'name' => $this->name,
        ];

        if ($this->authorizationToken !== null) {
            $payload['authorization_token'] = $this->authorizationToken;
        }

        if ($this->defaultConfig !== null) {
            $payload['default_config'] = $this->defaultConfig;
        }

        if ($this->configs !== null) {
            $payload['configs'] = $this->configs;
        }

        return $payload;
    }
}
