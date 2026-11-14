<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FileRequest extends FormRequest
{
    // A path inside the server's folder: no parent steps, no backslashes, no control characters.
    // The Rust service checks again and also refuses symlinks that lead outside.
    private const string PATH = 'not_regex:/(^|\/)\.\.?(\/|$)|[\\\\\x00-\x1F\x7F]/';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'path' => ['nullable', 'string', 'max:1000', self::PATH],
            'edit' => ['nullable', 'string', 'max:1000', self::PATH],
            'from' => ['nullable', 'string', 'max:1000', self::PATH],
            'to' => ['nullable', 'string', 'max:1000', self::PATH],
            // The editor is for text files up to the size the Rust service will read back.
            'content' => ['nullable', 'string', 'max:1048576'],
        ];
    }

    /**
     * Get a validated path without leading or trailing slashes.
     */
    public function path(string $key = 'path'): string
    {
        return trim((string) $this->validated($key), '/');
    }
}
