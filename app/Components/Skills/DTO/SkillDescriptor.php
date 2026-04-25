<?php

declare(strict_types=1);

namespace App\Components\Skills\DTO;

final readonly class SkillDescriptor
{
    public function __construct(
        public string $type,
        public string $name,
        public ?string $id = null,
        public ?string $version = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
            name: $data['name'] ?? '',
            id: $data['id'] ?? null,
            version: $data['version'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = ['type' => $this->type, 'name' => $this->name];

        if ($this->id !== null) {
            $payload['id'] = $this->id;
        }

        if ($this->version !== null) {
            $payload['version'] = $this->version;
        }

        return $payload;
    }
}
