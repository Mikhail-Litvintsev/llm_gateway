<?php

declare(strict_types=1);

namespace App\Http\Requests\Sessions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SendSessionMessageRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:user',
            'messages.*.content' => 'required',
            'stream' => 'sometimes|boolean',
            'max_tokens' => 'sometimes|integer|min:1|max:200000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $forbidden = ['model_alias', 'system', 'tools', 'context_management'];
            foreach ($forbidden as $field) {
                if ($this->has($field)) {
                    $validator->errors()->add($field, "Field \"$field\" not allowed on session message");
                }
            }
        });
    }
}
