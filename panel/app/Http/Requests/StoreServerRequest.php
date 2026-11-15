<?php

namespace App\Http\Requests;

use App\Concerns\ServerValidationRules;
use App\Models\Node;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreServerRequest extends FormRequest
{
    use ServerValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->serverRules(),
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', 'unique:servers,slug'],
            'eula' => ['accepted'],
            // The host the server runs on; the first node when there is only one to choose from.
            'node_id' => ['nullable', 'integer', 'exists:nodes,id'],
            // The server is created without starting, so a world zip can be put in first.
            'world_zip' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]*$/'],
            // A world folder inside the node's import folder; the Rust service checks it again.
            'import_path' => ['nullable', 'string', 'max:1000', 'not_regex:/(^|\/)\.\.?(\/|$)|[\\\\\x00-\x1F\x7F]/'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (Node::query()->doesntExist()) {
                    $validator->errors()->add('name', __('Register a node first: php artisan node:register.'));
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lowercase letters, digits and hyphens only.'),
            'slug.unique' => __('Another server already uses this hostname.'),
            'eula.accepted' => __('The server cannot start until you accept the Minecraft EULA.'),
        ];
    }
}
