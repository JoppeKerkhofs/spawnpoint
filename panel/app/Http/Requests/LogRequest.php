<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LogRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            // The name becomes part of a path on the node, so it may only be a plain log file name.
            'file' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-][A-Za-z0-9._-]*\.log(\.gz)?$/'],
            'q' => ['nullable', 'string', 'max:200'],
        ];
    }
}
