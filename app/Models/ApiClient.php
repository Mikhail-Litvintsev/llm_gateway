<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'api_key_hash',
        'api_key_prefix',
        'previous_key_hash',
        'previous_key_expires_at',
        'signing_secret',
        'is_active',
        'rate_limit',
        'allowed_providers',
        'dev_mode',
    ];

    protected $hidden = [
        'api_key_hash',
        'previous_key_hash',
        'signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'dev_mode' => 'boolean',
            'allowed_providers' => 'array',
            'rate_limit' => 'integer',
            'previous_key_expires_at' => 'datetime',
        ];
    }

    public function callbackUrls(): HasMany
    {
        return $this->hasMany(CallbackUrl::class);
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }
}
