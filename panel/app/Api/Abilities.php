<?php

namespace App\Api;

use App\Models\User;

/**
 * What an API token may be allowed to do. The one source of truth: the ability middleware, the
 * token form, the pairing screen and the API documentation all read this.
 *
 * A token never exceeds its owner: these say what the token may ask for, the policies and gates
 * still say what its owner may do.
 */
class Abilities
{
    // Names issued before 1.5.0 that are no longer offered, and what they stand for now.
    public const array LEGACY = ['backups:manage' => ['backups:read', 'backups:create']];

    /**
     * Every ability, by group. The labels are what the token form shows.
     *
     * @return list<array{key: string, label: string, abilities: list<array{name: string, label: string, description: string}>}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'servers', 'label' => (string) __('Servers'), 'abilities' => [
                ['name' => 'servers:read', 'label' => (string) __('See servers'), 'description' => (string) __('List servers and read their live state.')],
                ['name' => 'servers:power', 'label' => (string) __('Power'), 'description' => (string) __('Start, stop, restart and kill servers.')],
                ['name' => 'servers:configure', 'label' => (string) __('Configure'), 'description' => (string) __("Change a server's settings and recreate its container.")],
                ['name' => 'servers:manage', 'label' => (string) __('Create and delete'), 'description' => (string) __('Create and delete servers, move them between nodes, and add DNS records.')],
            ]],
            ['key' => 'console', 'label' => (string) __('Console and logs'), 'abilities' => [
                ['name' => 'console:use', 'label' => (string) __('Use the console'), 'description' => (string) __('Open the live console. Sending commands still needs that permission on the server.')],
                ['name' => 'logs:read', 'label' => (string) __('Read logs'), 'description' => (string) __('List, read and download log files.')],
            ]],
            ['key' => 'files', 'label' => (string) __('Files'), 'abilities' => [
                ['name' => 'files:read', 'label' => (string) __('Read files'), 'description' => (string) __('Browse folders, read and download files.')],
                ['name' => 'files:write', 'label' => (string) __('Write files'), 'description' => (string) __('Save, upload, rename, create folders and delete.')],
            ]],
            ['key' => 'backups', 'label' => (string) __('Backups'), 'abilities' => [
                ['name' => 'backups:read', 'label' => (string) __('See backups'), 'description' => (string) __('List backups and download one.')],
                ['name' => 'backups:create', 'label' => (string) __('Make backups'), 'description' => (string) __('Start a backup, stop a running one and set how long they are kept.')],
                ['name' => 'backups:restore', 'label' => (string) __('Restore'), 'description' => (string) __('Restore a backup, lock one, and import a world.')],
                ['name' => 'backups:delete', 'label' => (string) __('Delete backups'), 'description' => (string) __('Remove a backup for good.')],
            ]],
            ['key' => 'schedules', 'label' => (string) __('Schedules'), 'abilities' => [
                ['name' => 'schedules:manage', 'label' => (string) __('Schedules'), 'description' => (string) __('Add, pause and remove scheduled actions.')],
            ]],
            ['key' => 'administration', 'label' => (string) __('Administration'), 'abilities' => [
                ['name' => 'nodes:read', 'label' => (string) __('See nodes'), 'description' => (string) __('List the hosts that run servers and their state.')],
                ['name' => 'nodes:manage', 'label' => (string) __('Manage nodes'), 'description' => (string) __('Add, change and remove nodes.')],
                ['name' => 'users:read', 'label' => (string) __('See users'), 'description' => (string) __('List users and what is shared with them.')],
                ['name' => 'users:manage', 'label' => (string) __('Manage users'), 'description' => (string) __('Invite, change and remove users and their shares.')],
                ['name' => 'settings:read', 'label' => (string) __('See settings'), 'description' => (string) __('Read the panel settings. Secrets are never sent.')],
                ['name' => 'settings:manage', 'label' => (string) __('Change settings'), 'description' => (string) __('Change the panel settings.')],
                ['name' => 'activity:read', 'label' => (string) __('Read the activity log'), 'description' => (string) __('See who did what in the panel.')],
            ]],
            ['key' => 'account', 'label' => (string) __('Own account'), 'abilities' => [
                ['name' => 'profile:manage', 'label' => (string) __('Change your name and language'), 'description' => (string) __('Nothing else: not your e-mail, password, two-factor or tokens.')],
                ['name' => 'webhooks:manage', 'label' => (string) __('Webhooks'), 'description' => (string) __('Add, change, test and remove your own webhooks.')],
                ['name' => 'modpacks:search', 'label' => (string) __('Search modpacks'), 'description' => (string) __('Search Modrinth, CurseForge and FTB.')],
            ]],
        ];
    }

    /**
     * Every ability name, in the order the groups list them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(fn (array $group): array => array_column($group['abilities'], 'name'), self::groups()));
    }

    /**
     * The three starting points the token form offers.
     *
     * @return array<string, list<string>>
     */
    public static function presets(): array
    {
        $readOnly = ['servers:read', 'logs:read', 'files:read', 'backups:read', 'activity:read'];

        return [
            'read-only' => $readOnly,
            'operator' => [...$readOnly, 'servers:power', 'console:use', 'files:write', 'backups:create', 'backups:restore', 'schedules:manage'],
            'full' => self::all(),
        ];
    }

    /**
     * What a token's stored abilities mean now, with retired names expanded. "*" is Sanctum's
     * "everything" and is left alone.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public static function resolve(array $granted): array
    {
        $resolved = [];

        foreach ($granted as $ability) {
            array_push($resolved, ...(self::LEGACY[$ability] ?? [$ability]));
        }

        return array_values(array_unique($resolved));
    }

    /**
     * The names that give an ability: itself, and any retired name that stands for it. The route
     * guard asks the token for each, because a test token only answers can(), it has no list.
     *
     * @return list<string>
     */
    public static function grants(string $ability): array
    {
        return [$ability, ...array_keys(array_filter(self::LEGACY, fn (array $stands): bool => in_array($ability, $stands, true)))];
    }

    /**
     * What the picker shows a user: a viewer could never use the administration abilities, so
     * they are not offered them, in the groups or in a preset.
     *
     * @return array{groups: list<array{key: string, label: string, abilities: list<array{name: string, label: string, description: string}>}>, presets: array<string, list<string>>}
     */
    public static function offeredTo(User $user): array
    {
        $groups = array_values(array_filter(self::groups(), fn (array $group): bool => $user->isAdmin() || $group['key'] !== 'administration'));
        $names = array_merge(...array_map(fn (array $group): array => array_column($group['abilities'], 'name'), $groups));

        return [
            'groups' => $groups,
            'presets' => array_map(fn (array $preset): array => array_values(array_intersect($preset, $names)), self::presets()),
        ];
    }
}
