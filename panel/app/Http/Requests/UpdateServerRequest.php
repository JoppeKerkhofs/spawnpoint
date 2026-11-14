<?php

namespace App\Http\Requests;

use App\Concerns\ServerValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServerRequest extends FormRequest
{
    use ServerValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return $this->serverRules();
    }
}
