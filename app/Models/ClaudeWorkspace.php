<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ClaudeWorkspace extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function decryptedApiKey(): string
    {
        return Crypt::decryptString($this->api_key_encrypted);
    }
}
