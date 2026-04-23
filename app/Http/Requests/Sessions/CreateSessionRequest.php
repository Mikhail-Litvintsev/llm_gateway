<?php

declare(strict_types=1);

namespace App\Http\Requests\Sessions;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'model_alias' => 'required|string|max:64',
            'system' => 'nullable|string|max:65535',
            'tools' => 'sometimes|array',
            'cache_strategy' => 'sometimes|string|in:auto_top_level,none,manual',
            'context_management' => 'sometimes|array',
            'context_management.compaction' => 'sometimes|array',
            'context_management.clear_tool_uses' => 'sometimes|array',
            'context_management.clear_thinking' => 'sometimes|array',
            'auto_resume' => 'sometimes|boolean',
            'expires_at' => 'sometimes|date',
        ];
    }
}
