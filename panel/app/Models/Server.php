<?php

namespace App\Models;

use App\Services\Proxy;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $node_id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string $hostname
 * @property string $server_type
 * @property string $mc_version
 * @property int $java_version
 * @property int $memory_mb
 * @property array<string, mixed> $settings Game settings; servers saved before a setting existed lack its key.
 * @property array{project: string, name: string, icon: string|null, version?: string|null, version_name?: string|null}|null $modpack
 * @property string|null $icon The uploaded picture: a 64x64 PNG as base64.
 * @property list<string>|null $client_files The files and folders a player's launcher copies.
 * @property bool $bluemap_enabled
 * @property bool $is_default
 * @property bool $pack_check
 * @property bool $pack_auto_update
 * @property string|null $pack_latest
 * @property int $backup_keep
 * @property int|null $backup_retention_days
 * @property Carbon $eula_accepted_at
 * @property-read Node $node
 */
#[Fillable(['node_id', 'owner_id', 'name', 'slug', 'hostname', 'server_type', 'mc_version', 'java_version', 'memory_mb', 'settings', 'modpack', 'bluemap_enabled', 'is_default', 'pack_check', 'pack_auto_update', 'pack_latest', 'backup_keep', 'backup_retention_days', 'eula_accepted_at', 'icon', 'client_files'])]
// The picture travels as "picture" (forUser), not as raw base64 in every list.
#[Hidden(['icon'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    // These lists mirror the ones the Rust service accepts (service/src/main.rs).
    public const array TYPES = ['VANILLA', 'PAPER', 'FABRIC', 'FORGE', 'NEOFORGE', 'PUMPKIN', 'MODRINTH', 'AUTO_CURSEFORGE', 'FTBA'];

    // Server types that install a whole modpack instead of one loader.
    public const array PACK_TYPES = ['MODRINTH', 'AUTO_CURSEFORGE', 'FTBA'];

    // Loaders whose mods the Mods page can install; modpacks are built on one of them.
    public const array MOD_LOADERS = ['FABRIC', 'FORGE', 'NEOFORGE'];

    // Where Modrinth, CurseForge and FTB serve pack pictures. A picture address is chosen by
    // whoever configures a server, so nothing else is fetched or shown. The node has the same list.
    public const array PICTURE_PREFIXES = ['https://cdn.modrinth.com/', 'https://media.forgecdn.net/', 'https://cdn.feed-the-beast.com/'];

    public const array JAVA_VERSIONS = [8, 17, 21, 25];

    // What a viewer can be allowed on a shared server, beyond seeing it and watching its console.
    public const array PERMISSIONS = ['logs', 'commands', 'power', 'files', 'backups', 'configure'];

    // Where a file for players may lie, below the folder itself: a launcher takes nothing else.
    public const array CLIENT_ROOTS = ['config', 'defaultconfigs', 'kubejs', 'scripts'];

    public const array DIFFICULTIES = ['peaceful', 'easy', 'normal', 'hard'];

    public const array MODES = ['survival', 'creative', 'adventure', 'spectator'];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * The viewers this server is shared with.
     *
     * @return BelongsToMany<User, $this>
     */
    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('permissions');
    }

    /**
     * What a user may do on this server: every permission plus "admin" for an admin, the
     * permissions of the share for a viewer, and nothing for anyone else.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user): array
    {
        if ($user->isAdmin()) {
            return [...self::PERMISSIONS, 'admin'];
        }

        $share = DB::table('server_user')->where(['server_id' => $this->id, 'user_id' => $user->id])->first();

        return $share === null ? [] : array_values(array_intersect(self::PERMISSIONS, json_decode((string) $share->permissions, true) ?? []));
    }

    /**
     * The server as the pages get it: its data plus what the user may do with it.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        return [...$this->toArray(), 'can' => $this->permissionsFor($user), 'node' => $this->node->name, 'has_mods' => $this->hasMods(), 'picture' => $this->picture(), 'has_own_picture' => $this->icon !== null];
    }

    /**
     * What to show for this server: the uploaded picture, else its modpack's, else nothing.
     */
    public function picture(): ?string
    {
        return $this->icon !== null ? "data:image/png;base64,{$this->icon}" : $this->packPicture();
    }

    /**
     * The modpack's picture, when it comes from one of the catalogues' own picture hosts.
     */
    public function packPicture(): ?string
    {
        $address = (string) ($this->modpack['icon'] ?? '');

        return preg_match('/^[!-~]{1,300}$/', $address) === 1 && Str::startsWith($address, self::PICTURE_PREFIXES) ? $address : null;
    }

    /**
     * Whether mods can be added to this server: a mod loader, or a modpack built on one.
     */
    public function hasMods(): bool
    {
        return in_array($this->server_type, [...self::MOD_LOADERS, ...self::PACK_TYPES], true);
    }

    /**
     * Limit a query to the servers a user may see: all of them for an admin, the shared ones for a viewer.
     *
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->whereHas('viewers', fn (Builder $viewers) => $viewers->whereKey($user->id));
        }
    }

    /**
     * Both proxies on the panel's host follow where a server is and what it is called.
     */
    /**
     * Whether players get this file: it was chosen, or lies in a folder that was.
     */
    public function sendsToPlayers(string $path): bool
    {
        return array_any($this->client_files ?? [], fn (string $chosen): bool => $path === $chosen || str_starts_with($path, "{$chosen}/"));
    }

    protected static function booted(): void
    {
        static::saved(function (self $server): void {
            if ($server->wasRecentlyCreated || $server->wasChanged(['node_id', 'hostname', 'bluemap_enabled', 'is_default'])) {
                Proxy::sync();
            }
        });
        static::deleted(fn () => Proxy::sync());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'java_version' => 'integer',
            'memory_mb' => 'integer',
            'settings' => 'array',
            'modpack' => 'array',
            'client_files' => 'array',
            'bluemap_enabled' => 'boolean',
            'is_default' => 'boolean',
            'pack_check' => 'boolean',
            'pack_auto_update' => 'boolean',
            'backup_keep' => 'integer',
            'backup_retention_days' => 'integer',
            'eula_accepted_at' => 'datetime',
        ];
    }
}
