<?php

declare(strict_types=1);

namespace App\Http\Requests\Sessions;

use Illuminate\Foundation\Http\FormRequest;

final class PaginateHistoryRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'from' => 'sometimes|integer|min:0',
            'limit' => 'sometimes|integer|min:1|max:200',
        ];
    }
}
