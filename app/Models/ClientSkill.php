<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $skill_id
 * @property int $client_id
 * @property ?string $anthropic_skill_id
 * @property string $name
 * @property ?string $version
 * @property bool $is_prebuilt
 * @property ?array<string, mixed> $metadata
 * @property bool $is_deleted
 */
class ClientSkill extends Model
{
    protected $table = 'client_skills';

    protected $fillable = [
        'skill_id',
        'client_id',
        'anthropic_skill_id',
        'name',
        'version',
        'is_prebuilt',
        'metadata',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_prebuilt' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
