<?php

namespace App\Concerns;

use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ServerValidationRules
{
    /**
     * Get the rules for the settings that can change after a server is created.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function serverRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'server_type' => ['required', Rule::in(Server::TYPES)],
            'mc_version' => ['required', 'string', 'max:16', 'regex:/^(LATEST|\d+(\.\d+)+)$/'],
            'java_version' => ['required', 'integer', Rule::in(Server::JAVA_VERSIONS)],
            'memory_mb' => ['required', 'integer', 'between:1024,32768'],
            'motd' => ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]*$/'],
            'difficulty' => ['required', Rule::in(Server::DIFFICULTIES)],
            'mode' => ['required', Rule::in(Server::MODES)],
            'max_players' => ['required', 'integer', 'between:1,1000'],
        ];
    }
}
