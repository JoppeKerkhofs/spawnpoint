<?php

namespace App\Concerns;

use App\Actions\Servers\UpdateServer;
use App\Models\Node;
use App\Models\Server;
use App\Models\Setting;
use App\Services\MemoryLimit;
use Closure;
use Illuminate\Validation\Rule;

trait ServerValidationRules
{
    /**
     * Get the rules for the settings that can change after a server is created.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function serverRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'server_type' => ['required', Rule::in(Server::TYPES)],
            'mc_version' => ['required', 'string', 'max:16', 'regex:/^(LATEST|\d+(\.\d+)+)$/'],
            'java_version' => ['required', 'integer', Rule::in(Server::JAVA_VERSIONS)],
            'memory_mb' => [
                'required',
                'integer',
                'between:1024,32768',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // On an update, the server's own current share does not count against it.
                    $server = $this->route('server');
                    $node = $server instanceof Server ? $server->node : (Node::query()->whereKey($this->input('node_id'))->first() ?? Node::query()->first());
                    $free = MemoryLimit::freeMb($node, $server instanceof Server ? $server : null);

                    if ($free !== null && (int) $value > $free) {
                        $fail(__('Only :free GB is left under the :limit GB memory limit.', [
                            'free' => max(0, round($free / 1024, 1)),
                            'limit' => round((int) MemoryLimit::mb($node) / 1024, 1),
                        ]));
                    }
                },
            ],
            'motd' => ['nullable', 'string', 'max:256', 'regex:/^[^\x00-\x1F\x7F]*$/'],
            'difficulty' => ['required', Rule::in(Server::DIFFICULTIES)],
            'mode' => ['required', Rule::in(Server::MODES)],
            'max_players' => ['required', 'integer', 'between:1,1000'],
            'modpack_project' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('server_type'), Server::PACK_TYPES, true)),
                'nullable',
                'string',
                'max:200',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $pattern = match ($this->input('server_type')) {
                        'AUTO_CURSEFORGE' => '/^[A-Za-z0-9-]+$/',
                        'FTBA' => '/^[0-9]{1,9}$/',
                        default => '/^(https:\/\/modrinth\.com\/)?[A-Za-z0-9._\/-]+$/',
                    };

                    if (! preg_match($pattern, (string) $value)) {
                        $fail(__('Pick a pack from the list, or paste a link to its Modrinth page.'));
                    } elseif ($this->input('server_type') === 'AUTO_CURSEFORGE' && blank(Setting::value('curseforge_key'))) {
                        $fail(__('CurseForge packs need CURSEFORGE_API_KEY in the panel environment.'));
                    }
                },
            ],
            'bluemap' => ['nullable', 'boolean'],
            'ops' => ['nullable', 'string', 'max:3400', $this->playerNames()],
            'whitelist' => ['nullable', 'boolean'],
            'whitelist_names' => ['nullable', 'string', 'max:3400', $this->playerNames()],
            'jvm_opts' => ['nullable', 'string', 'max:500', 'regex:/^[^\x00-\x1F\x7F]*$/'],
            'cpu_limit' => ['nullable', 'numeric', 'between:0.25,64'],
            'is_default' => ['nullable', 'boolean'],
            // A Modrinth version id or an FTB version number; empty installs the newest.
            'modpack_version' => ['nullable', 'string', 'max:16', $this->input('server_type') === 'FTBA' ? 'regex:/^[0-9]+$/' : 'regex:/^[A-Za-z0-9]+$/'],
            'modpack_version_name' => ['nullable', 'string', 'max:120'],
            'modpack_name' => ['nullable', 'string', 'max:120'],
            'modpack_icon' => ['nullable', 'string', 'max:500', 'starts_with:https://'],
        ];
    }

    /**
     * A rule for a list of Minecraft player names separated by commas or whitespace.
     */
    private function playerNames(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $names = UpdateServer::names((string) $value);

            if (count($names) > 200) {
                $fail(__('At most 200 names.'));
            }

            foreach ($names as $name) {
                if (! preg_match('/^[A-Za-z0-9_]{1,16}$/', $name)) {
                    $fail(__('":name" is not a Minecraft player name.', ['name' => $name]));

                    return;
                }
            }
        };
    }
}
