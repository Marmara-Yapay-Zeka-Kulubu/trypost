<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RejectPostRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('approve', $this->route('post'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_version' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9:._-]+\z/'],
        ];
    }
}
