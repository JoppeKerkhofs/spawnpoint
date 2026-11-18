<?php

namespace App\Concerns;

use App\Models\Node;
use Illuminate\Validation\Rule;

trait NodeValidationRules
{
    /**
     * The local node gets its name and address from the environment, so both may stay empty.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function nodeRules(?Node $node = null): array
    {
        $local = $node?->name === Node::LOCAL;

        return [
            'name' => [$local ? 'nullable' : 'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]*$/', Rule::unique('nodes', 'name')->ignore($node)],
            // Over http the panel signs every request but does not encrypt it, so that belongs on a
            // private network; https is a node that was given a certificate (SPAWNPOINT_TLS_CERT).
            'base_url' => [$local ? 'nullable' : 'required', 'string', 'max:255', 'regex:/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]{1,5})?\/?$/'],
            'dns_target' => ['nullable', 'string', 'max:253', 'regex:/^([0-9a-fA-F:.]+|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+)$/'],
            'bluemap_url' => ['nullable', 'url:http,https', 'max:255'],
            'memory_limit_mb' => ['nullable', 'integer', 'between:1024,4194304'],
        ];
    }
}
